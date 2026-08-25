<?php

namespace App\Services;

use App\Models\JobPost;
use App\Models\SavedJob;
use App\Models\Resume;

class JobMatchingService
{
    public function __construct(private LocalJobMatchingService $localMatcher)
    {
    }

    public function getRecommendedJobs($student)
    {
        $resume = Resume::where('student_id', $student->id)
            ->latest()
            ->first();

        $student->loadMissing(['skills', 'education']);

        $savedIds = SavedJob::where('student_id', $student->id)
            ->pluck('job_post_id')
            ->toArray();

        $jobs = JobPost::with([
            'company',
            'skills'
        ])
            ->where('status', 'Open')
            ->get();

        $matches = $this->localMatcher->matchJobsWithFallback($student, $jobs, $resume);

        $recommendedJobs = $jobs->map(function ($job) use ($matches, $savedIds) {
            $match = $this->formatMatchResult($matches[$job->id] ?? null);

            return [
                "job_id" => $job->id,
                "title" => $job->title,
                "company" => $job->company->company_name ?? null,
                "location" => $job->location,
                "salary" => $job->salary,
                "employment_type" => $job->employment_type,
                "level" => $job->level,
                "min_experience_years" => $job->min_experience_years,
                "max_experience_years" => $job->max_experience_years,
                "work_mode" => $job->work_mode,
                "match" => $match['match'],
                "recommendation_level" => $match['level'],
                "level_match" => $match['level'],
                "matching_skills" => $match['matching_skills'],
                "missing_skills" => $match['missing_skills'],
                "breakdown" => $match['breakdown'],
                "reasons" => $match['reasons'],
                "warnings" => $match['warnings'],
                "match_source" => $match['match_source'],
                "is_saved" => in_array($job->id, $savedIds)
            ];

        });

        return $recommendedJobs
            ->sortByDesc('match')
            ->values();
    }

    public function calculateMatch($student, $job)
    {
        $resume = Resume::where('student_id', $student->id)
            ->latest()
            ->first();

        return $this->formatMatchResult(
            $this->localMatcher->matchWithFallback($student, $job, $resume)
        );
    }

    public function calculateMatches($student, iterable $jobs): array
    {
        $resume = Resume::where('student_id', $student->id)
            ->latest()
            ->first();

        $student->loadMissing(['skills', 'education']);

        $matches = $this->localMatcher->matchJobsWithFallback($student, $jobs, $resume);

        return collect($jobs)
            ->mapWithKeys(fn ($job) => [
                $job->id => $this->formatMatchResult($matches[$job->id] ?? null),
            ])
            ->all();
    }

    public function calculateMatchWithResume($student, $job, ?Resume $resume = null)
    {
        return $this->formatMatchResult(
            $this->localMatcher->matchWithFallback($student, $job, $resume)
        );
    }

    public function formatMatchResult(?array $result): array
    {
        $score = (int) round((float) ($result['score'] ?? 0));

        return [
            "match" => max(0, min(100, $score)),
            "level" => $result['level'] ?? $this->level($score),
            "matching_skills" => array_values($result['matching_skills'] ?? []),
            "missing_skills" => array_values($result['missing_skills'] ?? []),
            "breakdown" => $result['breakdown'] ?? [],
            "reasons" => array_values($result['reasons'] ?? []),
            "warnings" => array_values($result['warnings'] ?? []),
            "match_source" => $result['match_source'] ?? 'local_python',
        ];
    }

    private function level(int $score): string
    {
        if ($score >= 90) {
            return 'Excellent Match';
        }

        if ($score >= 75) {
            return 'Good Match';
        }

        if ($score >= 60) {
            return 'Fair Match';
        }

        return 'Low Match';
    }
}
