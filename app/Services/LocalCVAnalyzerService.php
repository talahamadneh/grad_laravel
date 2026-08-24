<?php

namespace App\Services;

use App\Models\Resume;
use App\Models\ResumeAnalysis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LocalCVAnalyzerService
{
    public function __construct(private GroqService $groq)
    {
    }

    public function reviewAndStore(Resume $resume): array
    {
        $localResult = $this->analyzeLocally($resume);
        $enhancement = $this->optionalExternalEnhancement($resume, $localResult);

        ResumeAnalysis::updateOrCreate(
            ['resume_id' => $resume->id],
            [
                'cv_score' => $localResult['overall_score'],
                'ats_score' => $localResult['ats_score'],
                'strengths' => $localResult['strengths'],
                'weaknesses' => $localResult['weaknesses'],
                'missing_skills' => [],
                'recommendations' => $localResult['recommendations'],
            ]
        );

        return array_merge($localResult, [
            'ai_enhancement' => $enhancement,
        ]);
    }

    private function analyzeLocally(Resume $resume): array
    {
        $baseUrl = rtrim((string) config('services.cv_analyzer.url'), '/');

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.cv_analyzer.timeout', 8))
                ->post($baseUrl . '/analyze-cv', $this->payload($resume));
        } catch (Throwable $exception) {
            Log::error('Local CV analyzer unavailable', [
                'resume_id' => $resume->id,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('CV analysis service is temporarily unavailable. Please try again.');
        }

        if (!$response->successful()) {
            Log::error('Local CV analyzer returned an error', [
                'resume_id' => $resume->id,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            throw new RuntimeException('CV analysis service is temporarily unavailable. Please try again.');
        }

        $result = $response->json();

        if (!$this->isValidLocalResult($result)) {
            Log::error('Local CV analyzer returned invalid JSON structure', [
                'resume_id' => $resume->id,
                'response' => $result,
            ]);

            throw new RuntimeException('CV analysis service returned an invalid response. Please try again.');
        }

        return [
            'overall_score' => $this->score($result['overall_score']),
            'ats_score' => $this->score($result['ats_score']),
            'level' => (string) ($result['level'] ?? $this->level((int) $result['overall_score'])),
            'strengths' => array_values($result['strengths'] ?? []),
            'weaknesses' => array_values($result['weaknesses'] ?? []),
            'recommendations' => array_values($result['recommendations'] ?? []),
            'section_scores' => $result['section_scores'] ?? [],
            'analysis_source' => $result['analysis_source'] ?? 'local_python',
        ];
    }

    private function optionalExternalEnhancement(Resume $resume, array $localResult): array
    {
        if (!config('services.cv_external_ai.enabled')) {
            return [
                'status' => 'disabled',
                'provider' => config('services.ai_provider', 'groq'),
                'suggestions' => [],
            ];
        }

        if (config('services.ai_provider', 'groq') !== 'groq') {
            return [
                'status' => 'unavailable',
                'provider' => config('services.ai_provider'),
                'suggestions' => [],
            ];
        }

        try {
            $prompt = $this->enhancementPrompt($resume, $localResult);
            $result = $this->groq->generate($prompt);
            $cleaned = preg_replace('/```json|```/', '', $result);
            $parsed = json_decode(trim((string) $cleaned), true);

            if (!is_array($parsed)) {
                throw new RuntimeException('Groq returned non-JSON enhancement.');
            }

            return [
                'status' => 'available',
                'provider' => 'groq',
                'suggestions' => array_values($parsed['enhanced_suggestions'] ?? []),
                'improved_summary' => $parsed['improved_summary'] ?? null,
            ];
        } catch (Throwable $exception) {
            Log::warning('Optional CV AI enhancement failed', [
                'resume_id' => $resume->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 'unavailable',
                'provider' => 'groq',
                'suggestions' => [],
            ];
        }
    }

    private function payload(Resume $resume): array
    {
        $resume->loadMissing('student.user');
        $student = $resume->student;
        $user = $student?->user;

        // Data minimization: only CV fields needed by the local analyzer are sent.
        return [
            'professional_title' => $resume->professional_title ?? $student?->headline,
            'summary' => $resume->summary ?? $student?->bio,
            'skills' => $resume->skills ?? [],
            'education' => $resume->education ?? [],
            'experience' => $resume->experience ?? [],
            'projects' => $resume->projects ?? [],
            'certificates' => $resume->certificates ?? [],
            'languages' => $resume->languages ?? [],
            'contact' => [
                'email' => $user?->email,
                'phone' => $student?->phone,
                'linkedin' => $student?->linkedin,
                'github' => $student?->github,
                'portfolio' => $student?->portfolio,
            ],
        ];
    }

    private function enhancementPrompt(Resume $resume, array $localResult): string
    {
        return 'You are a resume writing assistant. The official CV score was produced by a local analyzer and must not be changed.

Return ONLY valid JSON:
{
  "enhanced_suggestions": ["suggestion 1", "suggestion 2"],
  "improved_summary": "optional improved summary based only on existing facts"
}

Do not invent experience, employers, achievements, skills, numbers, or education.

Resume:
' . json_encode($this->payload($resume), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '

Local analysis:
' . json_encode($localResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function isValidLocalResult(mixed $result): bool
    {
        return is_array($result)
            && isset($result['overall_score'], $result['ats_score'])
            && is_numeric($result['overall_score'])
            && is_numeric($result['ats_score'])
            && isset($result['strengths'], $result['weaknesses'], $result['recommendations'])
            && is_array($result['strengths'])
            && is_array($result['weaknesses'])
            && is_array($result['recommendations']);
    }

    private function score(mixed $value): int
    {
        return max(0, min(100, (int) round((float) $value)));
    }

    private function level(int $score): string
    {
        if ($score >= 85) {
            return 'Excellent';
        }

        if ($score >= 70) {
            return 'Good';
        }

        if ($score >= 50) {
            return 'Fair';
        }

        return 'Needs Improvement';
    }
}

