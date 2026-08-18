<?php

namespace App\Services;

use App\Models\JobPost;

class AutomaticJobValidationService
{
    private const AUTO_PUBLISH_SCORE = 80;

    public function validate(JobPost $job): array
    {
        $job->loadMissing('skills');

        $issues = [];
        $score = 100;

        $requiredChecks = [
            'title' => ['Job title is missing.', 18],
            'description' => ['Description is missing.', 18],
            'requirements' => ['Requirements are missing.', 16],
            'location' => ['Location is missing.', 10],
            'employment_type' => ['Employment type is missing.', 10],
            'work_mode' => ['Work mode is missing.', 10],
            'deadline' => ['Deadline is missing.', 10],
        ];

        foreach ($requiredChecks as $field => [$message, $penalty]) {
            if ($this->blank($job->{$field})) {
                $issues[] = [
                    'field' => $field,
                    'message' => $message,
                    'severity' => 'high',
                ];

                $score -= $penalty;
            }
        }

        if ($job->skills->isEmpty()) {
            $issues[] = [
                'field' => 'skills',
                'message' => 'Required skills are missing.',
                'severity' => 'high',
            ];

            $score -= 16;
        }

        $descriptionLength = mb_strlen(trim((string) $job->description));

        if (!$this->blank($job->description) && $descriptionLength < 120) {
            $issues[] = [
                'field' => 'description',
                'message' => 'Description is too short to clearly explain the role.',
                'severity' => 'medium',
            ];

            $score -= 10;
        }

        $requirementsLength = mb_strlen(trim((string) $job->requirements));

        if (!$this->blank($job->requirements) && $requirementsLength < 60) {
            $issues[] = [
                'field' => 'requirements',
                'message' => 'Requirements need more detail.',
                'severity' => 'medium',
            ];

            $score -= 8;
        }

        if ($job->deadline && $job->deadline->isPast()) {
            $issues[] = [
                'field' => 'deadline',
                'message' => 'Deadline is already in the past.',
                'severity' => 'high',
            ];

            $score -= 20;
        }

        if ($job->work_mode === 'Remote' && !$this->blank($job->location)) {
            $issues[] = [
                'field' => 'location',
                'message' => 'Remote job includes a location. Confirm whether this is a location restriction.',
                'severity' => 'low',
            ];

            $score -= 3;
        }

        if ($this->blank($job->salary)) {
            $issues[] = [
                'field' => 'salary',
                'message' => 'Salary information is missing.',
                'severity' => 'low',
            ];

            $score -= 5;
        }

        if ($job->skills->count() > 12) {
            $issues[] = [
                'field' => 'skills',
                'message' => 'Skills list is very broad. Confirm the required skills are focused.',
                'severity' => 'low',
            ];

            $score -= 4;
        }

        $score = max(0, min(100, $score));
        $status = $score >= self::AUTO_PUBLISH_SCORE && empty($this->highSeverityIssues($issues))
            ? 'Open'
            : 'Pending Review';

        return [
            'quality_score' => $score,
            'status' => $status,
            'issues' => $issues,
            'recommendation' => $status === 'Open' ? 'Publish Automatically' : 'Request Changes',
        ];
    }

    public function apply(JobPost $job): array
    {
        $result = $this->validate($job);

        $job->update([
            'status' => $result['status'],
            'quality_score' => $result['quality_score'],
            'moderation_issues' => $result['issues'],
            'moderation_recommendation' => $result['recommendation'],
            'moderated_at' => now(),
            'reviewed_at' => null,
        ]);

        if ($result['status'] === 'Open') {
            AdminActivityLogService::jobAutoPublished(
                $job->id,
                $job->title,
                $result['quality_score']
            );
        } else {
            AdminActivityLogService::jobSentForReview(
                $job->id,
                $job->title,
                $result['quality_score'],
                $result['issues']
            );

            NotificationService::jobNeedsManualReview($job->fresh('company'), $result);
        }

        return $result;
    }

    private function highSeverityIssues(array $issues): array
    {
        return array_values(array_filter(
            $issues,
            fn (array $issue) => ($issue['severity'] ?? null) === 'high'
        ));
    }

    private function blank(mixed $value): bool
    {
        if (is_null($value)) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return false;
    }
}
