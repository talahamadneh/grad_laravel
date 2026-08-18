<?php

namespace App\Services;

use App\Models\AdminActivityLog;

class AdminActivityLogService
{
    public static function log(
        string $action,
        string $targetType,
        ?int $targetId = null,
        ?string $description = null,
        string $actorType = 'System',
        ?int $actorId = null
    ): AdminActivityLog {
        return AdminActivityLog::create([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'description' => $description,
        ]);
    }

    public static function companyApproved(
        int $companyId,
        string $companyName,
        ?int $adminId = null
    ): AdminActivityLog {
        return self::log(
            'Company Approved',
            'Company',
            $companyId,
            "{$companyName} was approved.",
            $adminId ? 'Admin' : 'System',
            $adminId
        );
    }

    public static function companySentForReview(
        int $companyId,
        string $companyName
    ): AdminActivityLog {
        return self::log(
            'Company Sent For Review',
            'Company',
            $companyId,
            "{$companyName} was sent for admin review."
        );
    }

    public static function companySuspended(
        int $companyId,
        string $companyName,
        ?int $adminId = null
    ): AdminActivityLog {
        return self::log(
            'Company Suspended',
            'Company',
            $companyId,
            $adminId
                ? "{$companyName} was suspended by admin."
                : "{$companyName} was automatically suspended.",
            $adminId ? 'Admin' : 'System',
            $adminId
        );
    }

    public static function jobAutoPublished(
        int $jobId,
        string $jobTitle,
        int $qualityScore
    ): AdminActivityLog {
        return self::log(
            'Job Auto Published',
            'Job',
            $jobId,
            "{$jobTitle} was automatically published with quality score {$qualityScore}."
        );
    }

    public static function jobSentForReview(
        int $jobId,
        string $jobTitle,
        int $qualityScore,
        array $issues = []
    ): AdminActivityLog {
        $issueSummary = empty($issues)
            ? 'No issue summary available.'
            : collect($issues)
                ->pluck('message')
                ->filter()
                ->take(3)
                ->implode(' ');

        return self::log(
            'Job Sent For Review',
            'Job',
            $jobId,
            "{$jobTitle} was sent for admin review with quality score {$qualityScore}. {$issueSummary}"
        );
    }

    public static function jobApprovedByAdmin(
        int $jobId,
        string $jobTitle,
        ?int $adminId = null,
        ?string $note = null
    ): AdminActivityLog {
        return self::log(
            'Job Approved',
            'Job',
            $jobId,
            trim("{$jobTitle} was approved by admin. " . ($note ? "Note: {$note}" : '')),
            'Admin',
            $adminId
        );
    }

    public static function jobRejectedByAdmin(
        int $jobId,
        string $jobTitle,
        ?int $adminId = null,
        ?string $note = null
    ): AdminActivityLog {
        return self::log(
            'Job Rejected',
            'Job',
            $jobId,
            trim("{$jobTitle} was rejected by admin. " . ($note ? "Note: {$note}" : '')),
            'Admin',
            $adminId
        );
    }

    public static function jobChangesRequestedByAdmin(
        int $jobId,
        string $jobTitle,
        ?int $adminId = null,
        ?string $note = null
    ): AdminActivityLog {
        return self::log(
            'Job Changes Requested',
            'Job',
            $jobId,
            trim("Changes were requested for {$jobTitle}. " . ($note ? "Note: {$note}" : '')),
            'Admin',
            $adminId
        );
    }

    public static function jobSuspendedByAdmin(
        int $jobId,
        string $jobTitle,
        ?int $adminId = null,
        ?string $note = null
    ): AdminActivityLog {
        return self::log(
            'Job Suspended',
            'Job',
            $jobId,
            trim("{$jobTitle} was suspended by admin. " . ($note ? "Note: {$note}" : '')),
            'Admin',
            $adminId
        );
    }
}
