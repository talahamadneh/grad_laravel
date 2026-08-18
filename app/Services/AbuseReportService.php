<?php

namespace App\Services;

use App\Models\AbuseReport;
use App\Models\Company;
use App\Models\JobPost;
use App\Models\Message;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AbuseReportService
{
    private const CLOSED_STATUSES = ['Resolved', 'Dismissed'];
    private const HIGH_RISK_REASONS = [
        'abuse',
        'fraud',
        'harassment',
        'scam',
        'spam',
        'threat',
    ];

    public function create(User $reporter, array $data): array
    {
        $entity = $this->findReportable($data['reportable_type'], (int) $data['reportable_id']);

        if (!$entity) {
            return [
                'error' => 'Reported entity not found.',
                'status' => 404,
            ];
        }

        if ($this->isSelfReport($reporter, $data['reportable_type'], $entity)) {
            return [
                'error' => 'You cannot report yourself or your own content.',
                'status' => 422,
            ];
        }

        if ($data['reportable_type'] === 'Message' && !$this->canReportMessage($reporter, $entity)) {
            return [
                'error' => 'You can only report messages from your own conversations.',
                'status' => 403,
            ];
        }

        $report = AbuseReport::create([
            'reporter_id' => $reporter->id,
            'reportable_type' => $data['reportable_type'],
            'reportable_id' => (int) $data['reportable_id'],
            'reason' => $data['reason'],
            'description' => $data['description'] ?? null,
            'status' => 'Pending',
        ]);

        $this->updateRiskLevelsForEntity($report->reportable_type, (int) $report->reportable_id);

        $report = $report->fresh(['reporter', 'reviewer']);

        if ($report->risk_level === 'High') {
            NotificationService::highRiskAbuseReport($report);
        }

        return [
            'report' => $this->format($report),
            'status' => 201,
        ];
    }

    public function list(array $filters = []): Collection
    {
        $query = AbuseReport::with(['reporter', 'reviewer'])
            ->latest();

        if (!empty($filters['status'])) {
            $query->where('status', $this->normalizeStatus($filters['status']));
        }

        if (!empty($filters['entity_type'])) {
            $query->where('reportable_type', $filters['entity_type']);
        }

        if (!empty($filters['risk_level'])) {
            $query->where('risk_level', $this->normalizeRiskLevel($filters['risk_level']));
        }

        return $query->get()
            ->map(fn (AbuseReport $report) => $this->format($report));
    }

    public function find(int $reportId): ?array
    {
        $report = AbuseReport::with(['reporter', 'reviewer'])->find($reportId);

        return $report ? $this->format($report, true) : null;
    }

    public function resolve(int $reportId, User $admin, ?string $adminNote = null): ?array
    {
        return $this->updateStatus($reportId, $admin, 'Resolved', $adminNote);
    }

    public function dismiss(int $reportId, User $admin, ?string $adminNote = null): ?array
    {
        return $this->updateStatus($reportId, $admin, 'Dismissed', $adminNote);
    }

    public function format(AbuseReport $report, bool $includeDetails = false): array
    {
        $entity = $this->findReportable($report->reportable_type, (int) $report->reportable_id);
        $formatted = [
            'id' => $report->id,
            'reporter' => [
                'id' => $report->reporter?->id,
                'name' => $report->reporter?->name,
                'role' => $report->reporter?->role,
            ],
            'entity_type' => $report->reportable_type,
            'entity_id' => $report->reportable_id,
            'reported_entity_name' => $this->reportableName($report->reportable_type, $entity),
            'reason' => $report->reason,
            'description' => $report->description,
            'status' => $report->status,
            'risk_level' => $report->risk_level ?: $this->calculateRiskLevelForEntity(
                $report->reportable_type,
                (int) $report->reportable_id
            ),
            'created_at' => $report->created_at,
            'updated_at' => $report->updated_at,
        ];

        if ($includeDetails) {
            $formatted['reported_entity'] = $this->reportableDetails($report->reportable_type, $entity);
            $formatted['reviewed_by'] = $report->reviewer ? [
                'id' => $report->reviewer->id,
                'name' => $report->reviewer->name,
                'role' => $report->reviewer->role,
            ] : null;
            $formatted['admin_note'] = $report->admin_note;
            $formatted['timestamps'] = [
                'created_at' => $report->created_at,
                'updated_at' => $report->updated_at,
            ];
        }

        return $formatted;
    }

    private function updateStatus(int $reportId, User $admin, string $status, ?string $adminNote): ?array
    {
        $report = AbuseReport::with(['reporter', 'reviewer'])->find($reportId);

        if (!$report) {
            return null;
        }

        if (in_array($report->status, self::CLOSED_STATUSES, true)) {
            return [
                'error' => 'Report is already closed.',
                'report' => $this->format($report),
            ];
        }

        $report->update([
            'status' => $status,
            'reviewed_by' => $admin->id,
            'admin_note' => $adminNote,
        ]);

        AdminActivityLogService::log(
            "Abuse Report {$status}",
            'AbuseReport',
            $report->id,
            "Abuse report #{$report->id} was {$status} by admin.",
            'Admin',
            $admin->id
        );

        return $this->format($report->fresh(['reporter', 'reviewer']), true);
    }

    private function findReportable(string $type, int $id): ?Model
    {
        return match ($type) {
            'Company' => Company::with('user')->find($id),
            'JobPost' => JobPost::with('company.user')->find($id),
            'Student' => Student::with('user')->find($id),
            'Message' => Message::with(['sender', 'receiver'])->find($id),
            default => null,
        };
    }

    private function isSelfReport(User $reporter, string $type, Model $entity): bool
    {
        return match ($type) {
            'Company', 'Student' => (int) $entity->user_id === (int) $reporter->id,
            'JobPost' => (int) ($entity->company?->user_id) === (int) $reporter->id,
            'Message' => (int) $entity->sender_id === (int) $reporter->id,
            default => false,
        };
    }

    private function canReportMessage(User $reporter, Model $message): bool
    {
        return (int) $message->sender_id === (int) $reporter->id
            || (int) $message->receiver_id === (int) $reporter->id;
    }

    private function updateRiskLevelsForEntity(string $type, int $id): void
    {
        AbuseReport::where('reportable_type', $type)
            ->where('reportable_id', $id)
            ->update([
                'risk_level' => $this->calculateRiskLevelForEntity($type, $id),
            ]);
    }

    private function calculateRiskLevelForEntity(string $type, int $id): string
    {
        $reports = AbuseReport::where('reportable_type', $type)
            ->where('reportable_id', $id)
            ->get(['reason']);

        if ($reports->contains(fn (AbuseReport $report) => $this->hasHighRiskReason($report->reason))) {
            return 'High';
        }

        $reportsCount = $reports->count();

        if ($reportsCount >= 5) {
            return 'High';
        }

        if ($reportsCount >= 3) {
            return 'Flag';
        }

        return 'Monitor';
    }

    private function hasHighRiskReason(?string $reason): bool
    {
        $reason = strtolower((string) $reason);

        foreach (self::HIGH_RISK_REASONS as $highRiskReason) {
            if (str_contains($reason, $highRiskReason)) {
                return true;
            }
        }

        return false;
    }

    private function reportableName(string $type, ?Model $entity): ?string
    {
        if (!$entity) {
            return null;
        }

        return match ($type) {
            'Company' => $entity->company_name,
            'JobPost' => $entity->title,
            'Student' => $entity->user?->name,
            'Message' => 'Message #' . $entity->id,
            default => null,
        };
    }

    private function reportableDetails(string $type, ?Model $entity): ?array
    {
        if (!$entity) {
            return null;
        }

        return match ($type) {
            'Company' => [
                'id' => $entity->id,
                'name' => $entity->company_name,
                'industry' => $entity->industry,
                'approval_status' => $entity->approval_status,
                'user_status' => $entity->user?->status,
            ],
            'JobPost' => [
                'id' => $entity->id,
                'title' => $entity->title,
                'company' => $entity->company?->company_name,
                'status' => $entity->status,
            ],
            'Student' => [
                'id' => $entity->id,
                'name' => $entity->user?->name,
                'major' => $entity->major,
                'verification_status' => $entity->verification_status,
                'user_status' => $entity->user?->status,
            ],
            'Message' => [
                'id' => $entity->id,
                'sender' => [
                    'id' => $entity->sender?->id,
                    'name' => $entity->sender?->name,
                    'role' => $entity->sender?->role,
                ],
                'receiver' => [
                    'id' => $entity->receiver?->id,
                    'name' => $entity->receiver?->name,
                    'role' => $entity->receiver?->role,
                ],
                'type' => $entity->type,
                'created_at' => $entity->created_at,
            ],
            default => null,
        };
    }

    private function normalizeStatus(string $status): string
    {
        return match (strtolower($status)) {
            'resolved' => 'Resolved',
            'dismissed' => 'Dismissed',
            default => 'Pending',
        };
    }

    private function normalizeRiskLevel(string $riskLevel): string
    {
        return match (strtolower($riskLevel)) {
            'high' => 'High',
            'flag' => 'Flag',
            default => 'Monitor',
        };
    }
}
