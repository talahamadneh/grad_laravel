<?php

namespace App\Services;

use App\Models\JobPost;
use App\Models\Resume;
use App\Models\Student;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LocalJobMatchingService
{
    public function match(Student $student, JobPost $job, ?Resume $resume = null): array
    {
        $payload = $this->payload($student, $job, $resume);
        $result = $this->postMatchJob($payload);

        return array_merge($result, [
            'match_source' => 'local_python',
        ]);
    }

    public function matchWithFallback(Student $student, JobPost $job, ?Resume $resume = null): array
    {
        try {
            return $this->match($student, $job, $resume);
        } catch (RuntimeException $exception) {
            return $this->phpFallback($this->payload($student, $job, $resume), $exception->getMessage());
        }
    }

    public function matchJobsWithFallback(Student $student, iterable $jobs, ?Resume $resume = null): array
    {
        $jobs = collect($jobs)->values();

        if ($jobs->isEmpty()) {
            return [];
        }

        $studentPayload = $this->studentPayload($student, $resume);
        $jobPayloads = $jobs
            ->map(fn (JobPost $job) => array_merge(
                ['id' => $job->id],
                $this->jobPayload($job)
            ))
            ->values()
            ->all();

        try {
            $results = $this->postMatchJobs([
                'student' => $studentPayload,
                'jobs' => $jobPayloads,
            ]);

            return collect($results['results'])
                ->filter(fn ($result) => isset($result['job_id']) && $this->isValidResult($result))
                ->mapWithKeys(fn ($result) => [
                    (int) $result['job_id'] => array_merge($result, [
                        'match_source' => 'local_python',
                    ]),
                ])
                ->all();
        } catch (RuntimeException $exception) {
            return $jobs
                ->mapWithKeys(fn (JobPost $job) => [
                    $job->id => $this->phpFallback([
                        'student' => $studentPayload,
                        'job' => $this->jobPayload($job),
                    ], $exception->getMessage()),
                ])
                ->all();
        }
    }

    public function payload(Student $student, JobPost $job, ?Resume $resume = null): array
    {
        return [
            'student' => $this->studentPayload($student, $resume),
            'job' => $this->jobPayload($job),
        ];
    }

    private function postMatchJob(array $payload): array
    {
        $baseUrl = rtrim((string) config('services.local_job_matcher.url'), '/');

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.local_job_matcher.timeout', 8))
                ->post($baseUrl . '/match-job', $payload);
        } catch (Throwable $exception) {
            Log::warning('Local job matcher unavailable', [
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Local job matching service is temporarily unavailable. Please try again.');
        }

        if (!$response->successful()) {
            Log::warning('Local job matcher returned an error', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            throw new RuntimeException('Local job matching service returned an error. Please try again.');
        }

        $result = $response->json();

        if (!$this->isValidResult($result)) {
            Log::warning('Local job matcher returned invalid JSON structure', [
                'response' => $result,
            ]);

            throw new RuntimeException('Local job matching service returned an invalid response. Please try again.');
        }

        return $result;
    }

    private function postMatchJobs(array $payload): array
    {
        $baseUrl = rtrim((string) config('services.local_job_matcher.url'), '/');

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.local_job_matcher.timeout', 8))
                ->post($baseUrl . '/match-jobs', $payload);
        } catch (Throwable $exception) {
            Log::warning('Local batch job matcher unavailable', [
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Local job matching service is temporarily unavailable. Please try again.');
        }

        if (!$response->successful()) {
            Log::warning('Local batch job matcher returned an error', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            throw new RuntimeException('Local job matching service returned an error. Please try again.');
        }

        $result = $response->json();

        if (!is_array($result) || !isset($result['results']) || !is_array($result['results'])) {
            Log::warning('Local batch job matcher returned invalid JSON structure', [
                'response' => $result,
            ]);

            throw new RuntimeException('Local job matching service returned an invalid response. Please try again.');
        }

        return $result;
    }

    private function studentPayload(Student $student, ?Resume $resume = null): array
    {
        $student->loadMissing(['skills', 'education']);

        $resume ??= Resume::where('student_id', $student->id)
            ->latest()
            ->first();

        return [
            'headline' => $student->headline,
            'major' => $student->major,
            'university' => $student->university,
            'location' => $student->location,
            'preferred_employment_type' => $student->preferred_employment_type,
            'bio' => $student->bio,
            'skills' => $student->skills
                ->pluck('name')
                ->filter()
                ->values()
                ->all(),
            'education' => $student->education
                ->map(fn ($item) => [
                    'degree' => $item->degree,
                    'major' => $item->major,
                    'university' => $item->university,
                ])
                ->values()
                ->all(),
            'resume' => $resume ? [
                'professional_title' => $resume->professional_title,
                'summary' => $resume->summary,
                'total_years_experience' => $resume->total_years_experience,
                'skills' => $resume->skills ?? [],
                'education' => $resume->education ?? [],
                'experience' => $resume->experience ?? [],
                'projects' => $resume->projects ?? [],
            ] : null,
        ];
    }

    private function jobPayload(JobPost $job): array
    {
        $job->loadMissing(['skills']);

        return [
            'title' => $job->title,
            'department' => $job->department,
            'description' => $job->description,
            'responsibilities' => $job->responsibilities,
            'requirements' => $job->requirements,
            'skills' => $job->skills
                ->pluck('name')
                ->filter()
                ->values()
                ->all(),
            'required_major' => $job->required_major,
            'employment_type' => $job->employment_type,
            'level' => $job->level,
            'work_mode' => $job->work_mode,
            'location' => $job->location,
            'min_experience_years' => $job->min_experience_years,
            'max_experience_years' => $job->max_experience_years,
        ];
    }

    private function phpFallback(array $payload, string $reason): array
    {
        $student = $payload['student'] ?? [];
        $job = $payload['job'] ?? [];
        $resume = $student['resume'] ?? [];

        $studentSkills = collect($resume['skills'] ?? [])
            ->merge($student['skills'] ?? [])
            ->map(fn ($skill) => $this->normalizeSkill($skill))
            ->filter()
            ->unique()
            ->values();

        $jobSkills = collect($job['skills'] ?? [])
            ->map(fn ($skill) => $this->normalizeSkill($skill))
            ->filter()
            ->unique()
            ->values();

        $matchingSkills = $jobSkills
            ->filter(fn ($skill) => $studentSkills->contains($skill))
            ->values()
            ->all();

        $missingSkills = $jobSkills
            ->reject(fn ($skill) => in_array($skill, $matchingSkills, true))
            ->values()
            ->all();

        $score = $jobSkills->isEmpty()
            ? 0
            : (int) round((count($matchingSkills) / max($jobSkills->count(), 1)) * 100);

        return [
            'score' => max(0, min(100, $score)),
            'level' => $this->level($score),
            'breakdown' => [
                'skills' => [
                    'score' => max(0, min(100, $score)),
                    'max_weight' => 100,
                    'applicable' => !$jobSkills->isEmpty(),
                    'status' => $jobSkills->isEmpty() ? 'criterion_not_applicable' : 'scored',
                ],
            ],
            'matching_skills' => $matchingSkills,
            'missing_skills' => $missingSkills,
            'reasons' => $matchingSkills
                ? ['Matched required skills: ' . implode(', ', $matchingSkills)]
                : [],
            'warnings' => [
                'Local Python matcher unavailable; used local PHP fallback.',
                $reason,
            ],
            'match_source' => 'local_php_fallback',
        ];
    }

    private function normalizeSkill(mixed $skill): string
    {
        if (is_array($skill)) {
            $skill = $skill['name'] ?? $skill['skill'] ?? $skill['title'] ?? '';
        }

        $skill = strtolower(trim((string) $skill));
        $skill = str_replace(['_', '-'], ' ', $skill);
        $skill = preg_replace('/\s+/', ' ', $skill);

        return match ($skill) {
            'js', 'java script' => 'javascript',
            'rest apis', 'restful api' => 'rest api',
            'laravel framework' => 'laravel',
            'mysql database', 'my sql' => 'mysql',
            'nodejs', 'node.js' => 'node',
            default => $skill,
        };
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

    private function isValidResult(mixed $result): bool
    {
        return is_array($result)
            && isset($result['score'], $result['level'], $result['breakdown'])
            && is_numeric($result['score'])
            && $result['score'] >= 0
            && $result['score'] <= 100
            && is_array($result['breakdown'])
            && isset($result['matching_skills'], $result['missing_skills'], $result['reasons'], $result['warnings'])
            && is_array($result['matching_skills'])
            && is_array($result['missing_skills'])
            && is_array($result['reasons'])
            && is_array($result['warnings']);
    }
}
