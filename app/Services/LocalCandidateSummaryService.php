<?php

namespace App\Services;

use App\Models\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LocalCandidateSummaryService
{
    public function summarize(Application $application, array $match): string
    {
        $response = $this->postSummary($this->payload($application, $match));
        $summary = trim((string) ($response['summary'] ?? ''));

        if ($summary === '') {
            throw new RuntimeException('Local candidate summary service returned an invalid response.');
        }

        return $summary;
    }

    public function payload(Application $application, array $match): array
    {
        $application->loadMissing([
            'student.skills',
            'resume',
            'jobPost',
        ]);

        $student = $application->student;
        $resume = $application->resume;

        return [
            'job_title' => $application->jobPost?->title,
            'match_percentage' => $match['percentage'] ?? $match['match'] ?? $application->match_score,
            'matching_skills' => array_values($match['matching_skills'] ?? []),
            'missing_skills' => array_values($match['missing_skills'] ?? []),
            'candidate_skills' => $this->candidateSkills($student, $resume),
            'professional_title' => $resume?->professional_title ?? $student?->headline,
            'major' => $student?->major,
            'relevant_experience' => $this->compactItems($resume?->experience ?? []),
            'relevant_projects' => $this->compactItems($resume?->projects ?? []),
        ];
    }

    private function postSummary(array $payload): array
    {
        $baseUrl = rtrim((string) config('services.local_job_matcher.url'), '/');

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.local_job_matcher.timeout', 8))
                ->post($baseUrl . '/candidate-summary', $payload);
        } catch (Throwable $exception) {
            Log::warning('Local candidate summary service unavailable', [
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Local candidate summary service is temporarily unavailable.');
        }

        if (!$response->successful()) {
            Log::warning('Local candidate summary service returned an error', [
                'status' => $response->status(),
            ]);

            throw new RuntimeException('Local candidate summary service is temporarily unavailable.');
        }

        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException('Local candidate summary service returned an invalid response.');
        }

        return $data;
    }

    private function candidateSkills($student, $resume): array
    {
        $resumeSkills = collect($resume?->skills ?? [])
            ->map(fn ($skill) => $this->skillName($skill))
            ->filter();

        $studentSkills = $student?->skills
            ? $student->skills->pluck('name')->filter()
            : collect();

        return $resumeSkills
            ->merge($studentSkills)
            ->unique(fn ($skill) => strtolower((string) $skill))
            ->values()
            ->all();
    }

    private function compactItems(array $items): array
    {
        return collect($items)
            ->map(function ($item) {
                if (!is_array($item)) {
                    return ['title' => trim((string) $item)];
                }

                return array_filter([
                    'title' => $item['title'] ?? $item['position'] ?? $item['name'] ?? null,
                    'company' => $item['company'] ?? null,
                    'description' => $item['description'] ?? null,
                ], fn ($value) => filled($value));
            })
            ->filter()
            ->values()
            ->all();
    }

    private function skillName(mixed $skill): string
    {
        if (is_array($skill)) {
            return trim((string) ($skill['name'] ?? $skill['title'] ?? ''));
        }

        return trim((string) $skill);
    }
}
