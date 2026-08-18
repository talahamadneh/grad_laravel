<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminReportService
{
    public function __construct(
        private readonly AdminAnalyticsService $analyticsService,
        private readonly AbuseReportService $abuseReportService
    ) {
    }

    public function platform(string $period = 'month'): array
    {
        $analytics = $this->analyticsService->getAnalytics($period);

        return [
            'report' => [
                'name' => $this->platformReportName($period),
                'period' => $analytics['period']['name'],
                'start' => $analytics['period']['start'],
                'end' => $analytics['period']['end'],
                'generated_at' => now()->toISOString(),
            ],
            'students' => [
                'total' => $analytics['students']['total_students'],
                'active' => $analytics['students']['active_students'],
                'new' => $analytics['students']['new_students'],
            ],
            'companies' => [
                'total' => $analytics['companies']['total_companies'],
                'active' => $analytics['companies']['active_companies'],
                'new' => $analytics['companies']['new_companies'],
            ],
            'jobs' => [
                'total' => $analytics['jobs']['total_jobs'],
                'open' => $analytics['jobs']['open_jobs'],
                'closed' => $analytics['jobs']['closed_jobs'],
                'pending_review' => $analytics['jobs']['pending_review_jobs'],
                'created' => $analytics['jobs']['jobs_created'],
            ],
            'applications' => [
                'total' => $analytics['hiring']['total_applications'],
                'pending' => $analytics['hiring']['pending_applications'],
                'shortlisted' => $analytics['hiring']['shortlisted_applications'],
                'interviews' => $analytics['hiring']['interview_applications'],
                'accepted' => $analytics['hiring']['accepted_applications'],
                'rejected' => $analytics['hiring']['rejected_applications'],
            ],
            'hiring' => [
                'total_interviews' => DB::table('interviews')->count(),
                'total_hires' => $analytics['hiring']['total_hires'],
                'hire_rate' => $analytics['hiring']['hire_rate'],
            ],
            'highlights' => [
                'top_majors' => $analytics['students']['top_majors'],
                'top_industries' => $analytics['companies']['top_industries'],
                'most_demanded_skills' => $analytics['jobs']['most_demanded_skills'],
                'most_requested_majors' => $analytics['jobs']['most_requested_majors'],
                'jobs_by_work_mode' => $analytics['jobs']['jobs_by_work_mode'],
                'jobs_by_employment_type' => $analytics['jobs']['jobs_by_employment_type'],
            ],
        ];
    }

    public function abuse(array $filters = []): array
    {
        $reports = $this->abuseReportService->list($filters);
        $limit = (int) ($filters['limit'] ?? 10);

        return [
            'statistics' => [
                'total' => $reports->count(),
                'pending' => $reports
                    ->where('status', 'Pending')
                    ->count(),
                'resolved' => $reports
                    ->where('status', 'Resolved')
                    ->count(),
                'dismissed' => $reports
                    ->where('status', 'Dismissed')
                    ->count(),
                'high_risk' => $reports
                    ->where('risk_level', 'High')
                    ->count(),
            ],
            'by_entity' => $reports
                ->groupBy('entity_type')
                ->map(fn (Collection $items, string $entityType) => [
                    'entity_type' => $entityType,
                    'count' => $items->count(),
                ])
                ->values(),
            'by_reason' => $reports
                ->groupBy(fn (array $report) => $report['reason'] ?: 'Unspecified')
                ->map(fn (Collection $items, string $reason) => [
                    'reason' => $reason,
                    'count' => $items->count(),
                ])
                ->sortByDesc('count')
                ->values(),
            'recent_reports' => $reports
                ->sortByDesc('created_at')
                ->take($limit)
                ->values(),
        ];
    }

    public function abuseDetails(int $reportId): ?array
    {
        return $this->abuseReportService->find($reportId);
    }

    public function resolveAbuseReport(int $reportId, $admin, ?string $adminNote = null): ?array
    {
        return $this->abuseReportService->resolve($reportId, $admin, $adminNote);
    }

    public function dismissAbuseReport(int $reportId, $admin, ?string $adminNote = null): ?array
    {
        return $this->abuseReportService->dismiss($reportId, $admin, $adminNote);
    }

    private function platformReportName(string $period): string
    {
        return match ($period) {
            'week' => 'Weekly Platform Report',
            'year' => 'Yearly Platform Report',
            default => 'Monthly Platform Report',
        };
    }

}
