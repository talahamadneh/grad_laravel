<?php

namespace App\Services;

use App\Models\Application;
use App\Models\JobPost;
use App\Models\Student;
use Throwable;

class AIJobMatchService
{
    public function __construct(private GeminiService $gemini)
    {
    }

    public function analyze(Application $application): array
    {
        $application->loadMissing([
            'resume',
            'student.user',
            'student.skills',
            'student.education',
            'student.experience',
            'student.projects',
            'student.certificates',
            'jobPost.company',
            'jobPost.skills',
        ]);

        if (!config('services.gemini.key')) {
            return $this->fallback($application, 'Gemini API key is missing.');
        }

        try {
            $result = $this->gemini->generate($this->prompt($application));
            $parsed = $this->parseJson($result);

            if (!$this->isValid($parsed)) {
                return $this->fallback($application, 'Gemini returned an invalid match response.');
            }

            return [
                'score' => max(0, min(100, (int) round($parsed['match_score']))),
                'analysis' => [
                    'level' => $parsed['level'] ?? $this->level((int) $parsed['match_score']),
                    'matching_points' => $parsed['matching_points'] ?? [],
                    'missing_points' => $parsed['missing_points'] ?? [],
                    'location_assessment' => $parsed['location_assessment'] ?? null,
                    'skills_assessment' => $parsed['skills_assessment'] ?? null,
                    'recommendation' => $parsed['recommendation'] ?? null,
                    'source' => 'ai',
                ],
                'source' => 'ai',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $this->fallback($application, 'Gemini matching failed.');
        }
    }

    private function fallback(Application $application, string $reason): array
    {
        $score = NotificationService::calculateApplicationMatchScore($application);

        return [
            'score' => $score,
            'analysis' => [
                'level' => $this->level($score),
                'matching_points' => [],
                'missing_points' => [],
                'location_assessment' => null,
                'skills_assessment' => null,
                'recommendation' => 'Fallback score calculated from skills, location, employment type, and major.',
                'source' => 'fallback',
                'fallback_reason' => $reason,
            ],
            'source' => 'fallback',
        ];
    }

    private function prompt(Application $application): string
    {
        $student = $application->student;
        $job = $application->jobPost;
        $resume = $application->resume;

        return 'You are an expert recruiting match analyst. Compare the candidate to the job using semantic understanding, not exact string matching. Treat related places, skills, majors, titles, and technologies as compatible when reasonable. For example, Ramallah can be compatible with Palestine, React.js with React, Laravel with PHP, and Computer Science with Software Engineering roles.

Return ONLY valid JSON with this exact shape:
{
  "match_score": 0,
  "level": "Low Match|Fair Match|Good Match|Excellent Match",
  "matching_points": ["point 1", "point 2"],
  "missing_points": ["point 1", "point 2"],
  "location_assessment": "short explanation",
  "skills_assessment": "short explanation",
  "recommendation": "Recommended for interview|Consider after review|Not recommended yet"
}

Scoring guidance:
- Skills and practical experience are most important.
- Location should be interpreted semantically and should not unfairly reduce the score when compatible.
- Major, projects, education, resume summary, and employment preference should influence the score.
- Be fair and realistic. Do not over-score candidates with weak evidence.

Candidate:
' . $this->studentSummary($student, $resume) . '

Job:
' . $this->jobSummary($job);
    }

    private function studentSummary(Student $student, $resume): string
    {
        return json_encode([
            'name' => $student->user->name ?? null,
            'headline' => $student->headline,
            'major' => $student->major,
            'university' => $student->university,
            'location' => $student->location,
            'preferred_employment_type' => $student->preferred_employment_type,
            'bio' => $student->bio,
            'skills' => $student->skills->pluck('name')->values(),
            'education' => $student->education->map(fn ($item) => [
                'degree' => $item->degree,
                'major' => $item->major,
                'university' => $item->university,
            ])->values(),
            'experience' => $student->experience->map(fn ($item) => [
                'position' => $item->position,
                'company' => $item->company,
                'description' => $item->description,
            ])->values(),
            'projects' => $student->projects->map(fn ($item) => [
                'title' => $item->title,
                'description' => $item->description,
            ])->values(),
            'resume' => $resume ? [
                'title' => $resume->title,
                'professional_title' => $resume->professional_title,
                'summary' => $resume->summary,
                'skills' => $resume->skills,
                'experience' => $resume->experience,
                'education' => $resume->education,
                'projects' => $resume->projects,
            ] : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function jobSummary(JobPost $job): string
    {
        return json_encode([
            'title' => $job->title,
            'company' => $job->company->company_name ?? null,
            'department' => $job->department,
            'description' => $job->description,
            'responsibilities' => $job->responsibilities,
            'requirements' => $job->requirements,
            'employment_type' => $job->employment_type,
            'level' => $job->level,
            'work_mode' => $job->work_mode,
            'location' => $job->location,
            'required_major' => $job->required_major,
            'skills' => $job->skills->pluck('name')->values(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function parseJson(string $result): ?array
    {
        $cleaned = preg_replace('/```json|```/', '', $result);
        $parsed = json_decode(trim($cleaned), true);

        return is_array($parsed) ? $parsed : null;
    }

    private function isValid(?array $parsed): bool
    {
        return is_array($parsed)
            && isset($parsed['match_score'])
            && is_numeric($parsed['match_score']);
    }

    private function level(int $score): string
    {
        if ($score >= 90) {
            return 'Excellent Match';
        }

        if ($score >= 75) {
            return 'Good Match';
        }

        if ($score >= 50) {
            return 'Fair Match';
        }

        return 'Low Match';
    }
}
