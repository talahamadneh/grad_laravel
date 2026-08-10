<?php

namespace App\Services;

use App\Models\AdminActivityLog;

class AdminActivityLogService
{
    public static function log(
        string $action,
        string $targetType,
        ?int $targetId = null,
        ?string $description = null
    ): AdminActivityLog {
        return AdminActivityLog::create([
            'actor_type' => 'System',
            'actor_id' => null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'description' => $description,
        ]);
    }

    public static function companyApproved(
        int $companyId,
        string $companyName
    ): AdminActivityLog {
        return self::log(
            'Company Approved',
            'Company',
            $companyId,
            "{$companyName} was approved."
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
        string $companyName
    ): AdminActivityLog {
        return self::log(
            'Company Suspended',
            'Company',
            $companyId,
            "{$companyName} was automatically suspended."
        );
    }

    public static function jobPublished(
        int $jobId,
        string $jobTitle
    ): AdminActivityLog {
        return self::log(
            'Job Published',
            'Job',
            $jobId,
            "{$jobTitle} was published."
        );
    }

    public static function jobSentForReview(
        int $jobId,
        string $jobTitle
    ): AdminActivityLog {
        return self::log(
            'Job Sent For Review',
            'Job',
            $jobId,
            "{$jobTitle} was sent for admin review."
        );
    }
}