<?php

namespace App\Services;

use App\Models\JobPost;
use App\Models\Resume;
use App\Models\Student;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class InterviewQuestionGenerationService
{
    private const TOTAL_QUESTIONS = 20;

    private array $lastContextStats = [];

    private bool $lastGenerationRetried = false;

    private array $lastGenerationStats = [];

    public function __construct(
        private GroqService $groq,
        private GeminiService $gemini,
        private DevDocsRetrievalService $devDocs,
    ) {
    }

    public function generate(JobPost $job, ?Student $student = null, array $avoidQuestions = []): array
    {
        $requestStartedAt = microtime(true);
        $timing = [
            'devdocs_retrieval_ms' => 0,
            'context_budget_ms' => 0,
            'groq_ms' => 0,
            'gemini_ms' => 0,
            'validation_ms' => 0,
            'total_request_ms' => 0,
        ];

        $job->loadMissing('skills');
        $student?->loadMissing(['skills', 'resumes']);

        $context = $this->context($job, $student);

        $stageStartedAt = microtime(true);
        $docs = $this->devDocs->retrieve(
            $context['job_skills'],
            $context['job_title'],
            trim(($context['job_description'] ?? '') . ' ' . ($context['job_requirements'] ?? '')),
            (string) ($context['job_level'] ?? '')
        );
        $timing['devdocs_retrieval_ms'] = $this->elapsedMs($stageStartedAt);

        if (empty($docs['covered_skills'] ?? []) && empty($docs['unsupported_technical_skills'] ?? [])) {
            throw new RuntimeException('No supported technical interview skills are available for this job.');
        }

        if (!config('services.interview_external_ai.enabled')) {
            throw new RuntimeException('Interview question generation is temporarily unavailable. External AI is disabled for this feature.');
        }

        $stageStartedAt = microtime(true);
        $docs = $this->docsWithinInputBudget($context, $docs);
        $timing['context_budget_ms'] = $this->elapsedMs($stageStartedAt);

        $questions = $this->generateWithAi($context, $docs, $avoidQuestions);
        $timing['groq_ms'] = (int) ($this->lastGenerationStats['timing_ms']['groq_ms'] ?? 0);
        $timing['gemini_ms'] = (int) ($this->lastGenerationStats['timing_ms']['gemini_ms'] ?? 0);
        $timing['validation_ms'] = (int) ($this->lastGenerationStats['timing_ms']['validation_ms'] ?? 0);
        $timing['total_request_ms'] = $this->elapsedMs($requestStartedAt);

        Log::info('Interview timing: request.', array_merge($timing, [
            'devdocs_timing_ms' => $docs['timing_ms'] ?? [],
            'final_question_count' => count($questions),
            'ai_calls' => $this->lastGenerationStats['total_ai_calls'] ?? 0,
        ]));

        return [
            'questions' => $questions,
            'metadata' => [
                'generation_source' => 'devdocs_grounded_ai',
                'documentation_source' => 'devdocs',
                'context_sections' => count($docs['sections']),
                'relevant_technical_skills' => $docs['relevant_technical_skills'] ?? [],
                'explicit_skills' => $docs['explicit_skills'] ?? $context['job_skills'],
                'detected_from_requirements' => $docs['detected_from_requirements'] ?? [],
                'retrieved_documents' => $docs['retrieved_documents'] ?? [],
                'source_docs' => $docs['retrieved_documents'] ?? [],
                'covered_skills' => $docs['covered_skills'],
                'uncovered_skills' => $docs['uncovered_skills'],
                'unsupported_technical_skills' => $docs['unsupported_technical_skills'] ?? [],
                'excluded_non_technical_skills' => $docs['excluded_non_technical_skills'] ?? [],
                'generation_sources' => collect($questions)->pluck('source')->unique()->values()->all(),
                'context_character_count' => $this->lastContextStats['documentation_context_chars'] ?? ($docs['context_character_count'] ?? null),
                'estimated_input_tokens' => $this->lastContextStats['estimated_input_tokens'] ?? null,
                'grounding_validation_retry_occurred' => $this->lastGenerationRetried,
                'ai_provider_used' => $this->lastGenerationStats['ai_provider_used'] ?? null,
                'fallback_used' => $this->lastGenerationStats['fallback_used'] ?? false,
                'generation_stats' => $this->lastGenerationStats,
                'timing_ms' => $timing,
                'devdocs_timing_ms' => $docs['timing_ms'] ?? [],
            ],
        ];
    }

    private function generateWithAi(array $context, array $docs, array $avoidQuestions = []): array
    {
        $this->lastGenerationRetried = false;
        $this->lastGenerationStats = [
            'first_call_question_count' => 0,
            'questions_preserved' => 0,
            'corrective_questions_requested' => 0,
            'total_ai_calls' => 0,
            'estimated_tokens_per_call' => [],
            'rate_limit_occurred' => false,
            'json_fallback_used' => false,
            'initial_valid_question_count' => 0,
            'initial_invalid_question_count' => 0,
            'initial_skill_distribution' => [],
            'coverage_repair_requests' => [],
            'semantic_duplicates_detected' => 0,
            'corrective_attempts_by_skill' => [],
            'corrective_topics_rotated_to' => [],
            'topic_diversity' => [],
            'primary_provider_attempted' => $this->primaryProvider(),
            'fallback_used' => false,
            'ai_provider_used' => null,
            'provider_calls' => [],
            'groq_failure_category' => null,
            'gemini_failure_category' => null,
            'groq_disabled_for_request' => false,
            'provider_switch_reason' => null,
            'corrective_calls_by_provider' => [
                'groq' => 0,
                'gemini' => 0,
            ],
            'timing_ms' => [
                'groq_ms' => 0,
                'gemini_ms' => 0,
                'validation_ms' => 0,
            ],
        ];

        $prompt = $this->prompt($context, $docs, self::TOTAL_QUESTIONS, $avoidQuestions);

        try {
            $raw = $this->generateJsonWithFallback($prompt, $context, $docs, self::TOTAL_QUESTIONS, $avoidQuestions);

            $stageStartedAt = microtime(true);
            $inspection = $this->inspectQuestions($raw, $docs);
            $this->lastGenerationStats['timing_ms']['validation_ms'] += $this->elapsedMs($stageStartedAt);
            $this->lastGenerationStats['first_call_question_count'] = $inspection['total'];
            $this->lastGenerationStats['initial_valid_question_count'] = count($inspection['valid']);
            $this->lastGenerationStats['initial_invalid_question_count'] = count($inspection['invalid']);
            $this->lastGenerationStats['initial_skill_distribution'] = $this->skillDistribution($inspection['valid']);

            $questions = $this->completeQuestionSet($inspection['valid'], $context, $docs, $avoidQuestions);

            return $questions;
        } catch (Throwable $exception) {
            $this->lastGenerationRetried = true;
            $this->lastGenerationStats['rate_limit_occurred'] = $this->lastGenerationStats['rate_limit_occurred'] || $this->isRateLimit($exception);
            Log::warning('Interview question generation failed.', [
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Interview question generation is temporarily unavailable. Please try again.', 0, $exception);
        }
    }

    private function prompt(array $context, array $docs, int $targetQuestions = self::TOTAL_QUESTIONS, array $avoidQuestions = []): string
    {
        $payload = [
            'job_context' => $this->minimalJobContext($context),
            'resume_context' => $this->minimalResumeContext($context),
            'relevant_technical_skills' => $docs['relevant_technical_skills'] ?? [],
            'covered_skills' => $docs['covered_skills'] ?? [],
            'uncovered_skills' => $docs['uncovered_skills'] ?? [],
            'unsupported_technical_skills' => $docs['unsupported_technical_skills'] ?? [],
            'excluded_non_technical_skills' => $docs['excluded_non_technical_skills'] ?? [],
            'trusted_documentation' => collect($docs['sections'])
                ->map(fn (array $section) => [
                    'doc_name' => $section['doc_name'],
                    'skill' => $section['skill'],
                    'source_reference' => $section['source_reference'],
                    'text' => $section['text'],
                ])
                ->values()
                ->all(),
            'avoid_previous_questions' => collect($avoidQuestions)
                ->filter()
                ->take(60)
                ->values()
                ->all(),
        ];

        return 'You generate interview questions for a career platform. DevDocs is the primary knowledge source. For covered_skills, use ONLY the supplied trusted DevDocs documentation and do not use outside knowledge. For unsupported_technical_skills only, you may use standard AI technical knowledge. If resume/project context is used, ask about the supplied project facts only and do not invent details.

Return ONLY valid JSON in this exact shape:
{
  "questions": [
    {
      "id": 1,
      "question": "Question text",
      "options": {"A": "Option", "B": "Option", "C": "Option", "D": "Option"},
      "correct_answer": "A",
      "difficulty": "easy",
      "skill": "JavaScript",
      "source": "devdocs",
      "source_doc": "JavaScript",
      "source_reference": "https://devdocs.io/..."
    }
  ]
}

Rules:
- Generate exactly ' . $targetQuestions . ' multiple-choice questions. Not fewer. Not more.
- The JSON array length MUST be exactly ' . $targetQuestions . '.
- Every question must have exactly four options: A, B, C, D.
- correct_answer must be one of A, B, C, D.
- Questions must be unique, practical for the job role, and concise.
- Avoid questions listed in avoid_previous_questions when possible.
- If perfect uniqueness conflicts with grounding, prefer valid grounded questions over invented questions.
- Keep each question under 22 words and each answer option under 14 words.
- Keep JSON compact. Do not add long explanations inside options.
- Use a mix of easy, medium, and hard questions.
- Most questions must cite source "devdocs".
- You may include at most 2 resume_context questions, and their source must be "resume_context".
- For source "devdocs", use only covered_skills and only facts directly supported by the cited source_reference.
- source_reference must exactly match one of the trusted_documentation source_reference values.
- For source "ai_knowledge", use only unsupported_technical_skills, set source_doc to "AI Knowledge", and set source_reference to null.
- Never use source "ai_knowledge" for a covered_skills item.
- Do not create technical questions for excluded_non_technical_skills.
- Keep skill coverage balanced across covered_skills and unsupported_technical_skills. Avoid more than 6 technical questions for one skill unless only one skill is available.
- Laravel questions must cite Laravel documentation only. Do not cite PHP documentation for Laravel framework behavior.
- REST API questions must cite HTTP documentation only.
- Git questions must be practical workflow questions, not obscure internals, unless the job explicitly asks for internals.
- Respect the job level when choosing difficulty. Junior roles should mostly use easy/medium practical questions.
- Do not include markdown, comments, explanations, or text outside JSON.

Input:
' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function simpleJsonPrompt(array $context, array $docs, int $targetQuestions, array $avoidQuestions = []): string
    {
        $payload = [
            'job' => [
                'title' => $context['job_title'],
                'level' => $context['job_level'] ?? null,
            ],
            'covered_skills' => $docs['covered_skills'] ?? [],
            'unsupported_technical_skills' => $docs['unsupported_technical_skills'] ?? [],
            'docs' => collect($docs['sections'])
                ->map(fn (array $section) => [
                    'skill' => $section['skill'],
                    'doc_name' => $section['doc_name'],
                    'source_reference' => $section['source_reference'],
                    'text' => $section['text'],
                ])
                ->values()
                ->all(),
            'avoid_previous_questions' => collect($avoidQuestions)->filter()->take(60)->values()->all(),
        ];

        return 'Return JSON only. No markdown.
Schema: {"questions":[{"id":1,"question":"text","options":{"A":"text","B":"text","C":"text","D":"text"},"correct_answer":"A","difficulty":"easy|medium|hard","skill":"covered or unsupported skill","source":"devdocs|ai_knowledge","source_doc":"doc_name or AI Knowledge","source_reference":"source_reference or null"}]}
Rules: exactly ' . $targetQuestions . ' questions; four options A-D; correct_answer A/B/C/D; devdocs source_reference must exactly equal one supplied docs source_reference; source ai_knowledge may use only unsupported_technical_skills and source_reference must be null; avoid duplicates and avoid_previous_questions when possible; keep skill coverage balanced; prefer grounded DevDocs questions for covered skills.
Input: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function generateJsonWithFallback(string $prompt, array $context, array $docs, int $targetQuestions, array $avoidQuestions = []): string
    {
        $primary = $this->primaryProvider();
        $secondary = $this->secondaryProvider($primary);

        if ($primary === 'groq' && ($this->lastGenerationStats['groq_disabled_for_request'] ?? false)) {
            return $this->callProviderFallback(
                $prompt,
                $context,
                $docs,
                $targetQuestions,
                $avoidQuestions,
                new RuntimeException('Groq is disabled for this request after rate limit.'),
                $primary,
                $secondary,
                false
            );
        }

        try {
            return $this->ensureQuestionsJson($this->callProviderJson($primary, $prompt, $targetQuestions));
        } catch (Throwable $exception) {
            $this->recordProviderFailure($primary, $exception);

            if (!$this->isJsonValidationFailure($exception)) {
                if ($this->shouldFallbackToSecondary($exception)) {
                    return $this->callProviderFallback($prompt, $context, $docs, $targetQuestions, $avoidQuestions, $exception, $primary, $secondary);
                }

                throw $exception;
            }

            $this->lastGenerationRetried = true;
            $this->lastGenerationStats['json_fallback_used'] = true;
            $fallbackPrompt = $this->simpleJsonPrompt($context, $docs, $targetQuestions, $avoidQuestions);

            try {
                return $this->ensureQuestionsJson($this->callProviderJson($primary, $fallbackPrompt, $targetQuestions));
            } catch (Throwable $fallbackException) {
                $this->recordProviderFailure($primary, $fallbackException);

                if ($this->shouldFallbackToSecondary($fallbackException)) {
                    return $this->callProviderFallback($fallbackPrompt, $context, $docs, $targetQuestions, $avoidQuestions, $fallbackException, $primary, $secondary, true);
                }

                throw $fallbackException;
            }
        }
    }

    private function callProviderJson(string $provider, string $prompt, int $targetQuestions): string
    {
        $this->recordAiCall($prompt, $provider);
        $stageStartedAt = microtime(true);

        try {
            $raw = $provider === 'gemini'
                ? $this->gemini->generateJson($prompt, $this->completionBudgetForProvider($targetQuestions, 'gemini'))
                : $this->groq->generateJson($prompt, $this->completionBudget($targetQuestions));
        } finally {
            $timingKey = $provider === 'gemini' ? 'gemini_ms' : 'groq_ms';
            $this->lastGenerationStats['timing_ms'][$timingKey] += $this->elapsedMs($stageStartedAt);
        }

        $this->lastGenerationStats['ai_provider_used'] = $provider;

        return $raw;
    }

    private function callProviderFallback(
        string $prompt,
        array $context,
        array $docs,
        int $targetQuestions,
        array $avoidQuestions,
        Throwable $primaryException,
        string $primaryProvider,
        string $fallbackProvider,
        bool $promptIsSimple = false
    ): string
    {
        $this->lastGenerationRetried = true;
        $this->lastGenerationStats['fallback_used'] = true;

        Log::warning('Interview AI provider fallback selected.', [
            'primary_provider' => $primaryProvider,
            'fallback_provider' => $fallbackProvider,
            'failure_category' => $this->failureCategory($primaryException),
        ]);

        try {
            return $this->ensureQuestionsJson($this->callProviderJson($fallbackProvider, $prompt, $targetQuestions));
        } catch (Throwable $exception) {
            $this->recordProviderFailure($fallbackProvider, $exception);

            if (!$promptIsSimple && $this->isJsonValidationFailure($exception)) {
                $this->lastGenerationStats['json_fallback_used'] = true;
                $fallbackPrompt = $this->simpleJsonPrompt($context, $docs, $targetQuestions, $avoidQuestions);

                try {
                    return $this->ensureQuestionsJson($this->callProviderJson($fallbackProvider, $fallbackPrompt, $targetQuestions));
                } catch (Throwable $fallbackException) {
                    $this->recordProviderFailure($fallbackProvider, $fallbackException);

                    throw $fallbackException;
                }
            }

            throw $exception;
        }
    }

    private function ensureQuestionsJson(string $raw): string
    {
        $parsed = $this->parseJson($raw);

        if (!isset($parsed['questions']) || !is_array($parsed['questions'])) {
            throw new RuntimeException('AI response did not contain a questions array.');
        }

        return $raw;
    }

    private function correctivePrompt(array $context, array $docs, array $preservedQuestions, int $needed, array $avoidQuestions = [], array $forcedSkills = [], ?array &$promptDocs = null, int $attemptNumber = 1): string
    {
        $neededSkills = $forcedSkills ?: $this->skillsNeedingQuestions($preservedQuestions, $docs, $needed);
        $correctiveContext = $this->correctiveDiversityContext($preservedQuestions, $docs, $neededSkills, $needed, $attemptNumber);
        $sections = collect($correctiveContext['sections'])->values();

        if ($sections->isEmpty()) {
            $sections = collect($docs['sections'])->take(2)->values();
        }

        $minimalDocs = array_merge($docs, [
            'sections' => $sections->map(function (array $section) {
                $section['text'] = $this->sentenceLimit((string) $section['text'], 450);

                return $section;
            })->all(),
            'covered_skills' => $sections->pluck('skill')->unique()->values()->all(),
        ]);
        $promptDocs = $minimalDocs;

        $avoid = collect($preservedQuestions)
            ->pluck('question')
            ->take(20)
            ->values()
            ->all();

        $avoid = collect($avoid)
            ->merge($avoidQuestions)
            ->unique()
            ->take(80)
            ->values()
            ->all();

        return $this->prompt($context, $minimalDocs, $needed, $avoidQuestions) . "\n\nCorrective generation only. Existing valid questions are already preserved. Generate only {$needed} NEW questions. Do not paraphrase an existing question. Do not test the same fact, edge case, API behavior, command behavior, or subtopic already tested. Choose a different concept from the supplied grounded documentation whenever possible. Use preferred_unused_topics and preferred_sections first. If a preferred section is exhausted, use another supplied section for that same skill. Avoid duplicates of these questions:\n"
            . json_encode($avoid, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nCorrective diversity requirements:\n"
            . json_encode($correctiveContext['targets'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nPrevious semantic fingerprints:\n"
            . json_encode($correctiveContext['fingerprints'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private function docsWithinInputBudget(array $context, array $docs): array
    {
        $maxTokens = max(1500, (int) config('services.devdocs.max_input_tokens', 4800));
        $sections = collect($docs['sections'] ?? [])->values();

        do {
            $candidate = array_merge($docs, ['sections' => $sections->values()->all()]);
            $prompt = $this->prompt($context, $candidate);
            $estimatedTokens = $this->estimateTokens($prompt);

            if ($estimatedTokens <= $maxTokens) {
                break;
            }

            $longestIndex = $sections
                ->map(fn (array $section, int $index) => ['index' => $index, 'length' => strlen($section['text'] ?? '')])
                ->sortByDesc('length')
                ->first();

            if (!$longestIndex || $longestIndex['length'] <= 320) {
                if ($sections->count() <= 1) {
                    break;
                }

                $sections = $sections->slice(0, -1)->values();
                continue;
            }

            $sections = $sections->map(function (array $section, int $index) use ($longestIndex) {
                if ($index !== $longestIndex['index']) {
                    return $section;
                }

                $section['text'] = $this->sentenceLimit(
                    (string) $section['text'],
                    max(300, (int) floor($longestIndex['length'] * 0.72))
                );

                return $section;
            })->values();
        } while (true);

        $docs['sections'] = $sections->values()->all();
        $docs['covered_skills'] = $sections->pluck('skill')->filter()->unique()->values()->all();
        $docs['context_character_count'] = $sections->sum(fn (array $section) => strlen($section['text'] ?? ''));
        $prompt = $this->prompt($context, $docs);

        $this->lastContextStats = [
            'sections' => $sections->count(),
            'documentation_context_chars' => $docs['context_character_count'],
            'estimated_input_tokens' => $this->estimateTokens($prompt),
            'max_input_tokens' => $maxTokens,
        ];

        Log::info('Interview DevDocs grounding context budget.', $this->lastContextStats);

        return $docs;
    }

    private function correctiveDiversityContext(array $preservedQuestions, array $docs, array $neededSkills, int $needed, int $attemptNumber): array
    {
        $usedBySkill = $this->usedConceptsBySkill($preservedQuestions);
        $sections = collect();
        $targets = [];

        foreach ($neededSkills as $skill) {
            $normalizedSkill = $this->normalizeSkill($skill);
            $skillMissingCount = max(1, (int) ceil($needed / max(1, count($neededSkills))));
            $usedTopics = collect($usedBySkill[$normalizedSkill] ?? [])->pluck('topic')->unique()->values()->all();
            $skillSections = collect($docs['sections'] ?? [])
                ->filter(fn (array $section) => $this->sameSkill($skill, (string) $section['skill']))
                ->map(function (array $section) use ($skill) {
                    $topic = $this->topicForQuestion(
                        (string) ($section['text'] ?? ''),
                        (string) ($section['skill'] ?? $skill),
                        (string) ($section['source_reference'] ?? '')
                    );

                    return array_merge($section, ['topic' => $topic]);
                })
                ->unique('source_reference')
                ->values();

            $unusedSections = $skillSections
                ->filter(fn (array $section) => !in_array((string) ($section['topic'] ?? 'general'), $usedTopics, true))
                ->values();
            $preferredSections = ($unusedSections->isNotEmpty() ? $unusedSections : $skillSections)->values();

            if ($preferredSections->isNotEmpty() && $attemptNumber > 1) {
                $rotation = ($attemptNumber - 1) % $preferredSections->count();
                $preferredSections = $preferredSections->slice($rotation)->concat($preferredSections->take($rotation))->values();
            }

            $take = count($neededSkills) === 1 ? min(4, max(2, $needed + 1)) : 2;
            $selectedSections = $preferredSections->take($take)->values();
            $sections = $sections->merge($selectedSections);

            $preferredTopics = $selectedSections
                ->pluck('topic')
                ->reject(fn (string $topic) => $topic === 'general')
                ->unique()
                ->values()
                ->all();

            $this->lastGenerationStats['corrective_topics_rotated_to'][] = [
                'skill' => $skill,
                'attempt' => $attemptNumber,
                'topics' => $preferredTopics,
                'source_references' => $selectedSections->pluck('source_reference')->values()->all(),
            ];

            $targets[] = [
                'skill' => $skill,
                'missing_question_count' => $skillMissingCount,
                'already_used_topics' => $usedTopics,
                'already_used_concepts' => collect($usedBySkill[$normalizedSkill] ?? [])->take(12)->values()->all(),
                'preferred_unused_topics' => $preferredTopics,
                'preferred_sections' => $selectedSections
                    ->map(fn (array $section) => [
                        'topic' => (string) ($section['topic'] ?? 'general'),
                        'source_reference' => (string) ($section['source_reference'] ?? ''),
                        'doc_name' => (string) ($section['doc_name'] ?? ''),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return [
            'sections' => $sections->unique('source_reference')->values()->all(),
            'targets' => $targets,
            'fingerprints' => $this->semanticFingerprintsBySkill($preservedQuestions, $neededSkills),
        ];
    }

    private function sentenceLimit(string $text, int $limit): string
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $selected = [];
        $used = 0;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            $length = strlen($sentence);

            if ($length === 0) {
                continue;
            }

            if ($used + $length > $limit) {
                break;
            }

            $selected[] = $sentence;
            $used += $length + 1;
        }

        if (!empty($selected)) {
            return implode(' ', $selected);
        }

        return Str::limit($text, $limit, '');
    }

    private function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function completeQuestionSet(array $validQuestions, array $context, array $docs, array $avoidQuestions = []): array
    {
        $validQuestions = $this->uniqueQuestions($validQuestions);

        if (count($validQuestions) > self::TOTAL_QUESTIONS) {
            return $this->selectBalancedQuestions($validQuestions, $docs);
        }

        $validQuestions = $this->trimOverrepresentedTopics(
            $this->trimOverrepresentedSkills($validQuestions, $docs),
            $docs
        );
        $this->lastGenerationStats['questions_preserved'] = count($validQuestions);

        $correctiveAttempts = 0;
        $maxCorrectiveAttempts = 6;
        $maxAttemptsPerSkill = 3;

        while (count($validQuestions) < self::TOTAL_QUESTIONS && $correctiveAttempts < $maxCorrectiveAttempts) {
            $beforeCount = count($validQuestions);
            $needed = self::TOTAL_QUESTIONS - count($validQuestions);
            $correctiveAttempts++;
            $this->lastGenerationRetried = true;
            $this->lastGenerationStats['corrective_questions_requested'] += $needed;
            $neededSkills = $this->skillsNeedingQuestions($validQuestions, $docs, $needed);
            $attemptNumber = $this->recordCorrectiveSkillAttempts($neededSkills, $maxAttemptsPerSkill);

            $promptDocs = $docs;
            $prompt = $this->correctivePrompt($context, $docs, $validQuestions, $needed, $avoidQuestions, $neededSkills, $promptDocs, $attemptNumber);

            try {
                $raw = $this->generateJsonWithFallback($prompt, $context, $promptDocs, $needed, $avoidQuestions);
            } catch (Throwable $exception) {
                $this->lastGenerationStats['rate_limit_occurred'] = $this->lastGenerationStats['rate_limit_occurred'] || $this->isRateLimit($exception);

                if ($this->isRateLimit($exception) && !($this->lastGenerationStats['fallback_used'] ?? false)) {
                    $this->backoffForRateLimit($exception);

                    $raw = $this->generateJsonWithFallback($prompt, $context, $promptDocs, $needed, $avoidQuestions);
                } else {
                    throw $exception;
                }
            }

            $stageStartedAt = microtime(true);
            $inspection = $this->inspectQuestions($raw, $docs, $validQuestions);
            $this->lastGenerationStats['timing_ms']['validation_ms'] += $this->elapsedMs($stageStartedAt);
            $validQuestions = $this->uniqueQuestions(array_merge($validQuestions, $inspection['valid']));
            $validQuestions = $this->trimOverrepresentedTopics($validQuestions, $docs);

            if ($beforeCount === count($validQuestions) && $correctiveAttempts >= $maxCorrectiveAttempts) {
                throw new RuntimeException('AI corrective response did not add enough valid grounded questions.');
            }
        }

        if (count($validQuestions) < self::TOTAL_QUESTIONS) {
            throw new RuntimeException('AI corrective response did not add enough valid grounded questions.');
        }

        $validQuestions = $this->repairSkillCoverage($validQuestions, $context, $docs, $avoidQuestions);
        $final = $this->selectBalancedQuestions($validQuestions, $docs);
        $this->lastGenerationStats['topic_diversity'] = $this->topicDistribution($final);
        $this->validateBalancedCoverage($final, $this->quizSkills($docs)->all());

        return $final;
    }

    private function trimOverrepresentedSkills(array $questions, array $docs): array
    {
        $coveredSkills = $this->quizSkills($docs);

        if ($coveredSkills->count() <= 1 || count($questions) < self::TOTAL_QUESTIONS) {
            return $questions;
        }

        $maxAllowed = max(6, (int) ceil(self::TOTAL_QUESTIONS / $coveredSkills->count()) + 2);
        $kept = collect();
        $counts = [];

        foreach ($questions as $question) {
            $skill = $this->normalizeSkill((string) $question['skill']);
            $counts[$skill] = $counts[$skill] ?? 0;

            if ($counts[$skill] >= $maxAllowed) {
                continue;
            }

            $counts[$skill]++;
            $kept->push($question);
        }

        return $kept->values()->all();
    }

    private function trimOverrepresentedTopics(array $questions, array $docs): array
    {
        $kept = collect();

        foreach (collect($questions)->groupBy(fn (array $question) => $this->normalizeSkill((string) $question['skill'])) as $skill => $group) {
            $supportedTopics = $this->supportedTopicsForSkill((string) $skill, $docs);

            if ($group->count() <= 6 || count($supportedTopics) < 2) {
                $kept = $kept->merge($group);
                continue;
            }

            $topicLimit = max(3, (int) ceil($group->count() * 0.6));
            $topicCounts = [];

            foreach ($group as $question) {
                $topic = (string) ($question['topic'] ?? $this->topicForQuestion(
                    (string) $question['question'],
                    (string) $question['skill'],
                    (string) ($question['source_reference'] ?? '')
                ));
                $topicCounts[$topic] = $topicCounts[$topic] ?? 0;

                if ($topicCounts[$topic] >= $topicLimit) {
                    $this->lastGenerationStats['semantic_duplicates_detected']++;
                    continue;
                }

                $topicCounts[$topic]++;
                $kept->push($question);
            }
        }

        return $kept->values()->all();
    }

    private function repairSkillCoverage(array $questions, array $context, array $docs, array $avoidQuestions = []): array
    {
        $targets = $this->targetSkillCounts($docs);

        if (empty($targets)) {
            return $questions;
        }

        $attempts = 0;
        $maxAttemptsPerSkill = 3;
        while ($attempts < 3) {
            $missing = $this->missingSkillCounts($questions, $targets);

            if (empty($missing)) {
                return $questions;
            }

            $attempts++;
            foreach ($missing as $skill => $needed) {
                $skillName = $this->skillNameFromTargets($skill, $targets);
                $this->lastGenerationRetried = true;
                $this->lastGenerationStats['corrective_questions_requested'] += $needed;
                $this->lastGenerationStats['coverage_repair_requests'][] = [
                    'skill' => $skillName,
                    'needed' => $needed,
                ];
                $attemptNumber = $this->recordCorrectiveSkillAttempts([$skillName], $maxAttemptsPerSkill);

                $promptDocs = $docs;
                $prompt = $this->correctivePrompt($context, $docs, $questions, $needed, $avoidQuestions, [$skillName], $promptDocs, $attemptNumber);

                try {
                    $raw = $this->generateJsonWithFallback($prompt, $context, $promptDocs, $needed, $avoidQuestions);
                } catch (Throwable $exception) {
                    $this->lastGenerationStats['rate_limit_occurred'] = $this->lastGenerationStats['rate_limit_occurred'] || $this->isRateLimit($exception);

                    if ($this->isRateLimit($exception) && !($this->lastGenerationStats['fallback_used'] ?? false)) {
                        $this->backoffForRateLimit($exception);

                        $raw = $this->generateJsonWithFallback($prompt, $context, $promptDocs, $needed, $avoidQuestions);
                    } else {
                        throw $exception;
                    }
                }

                $stageStartedAt = microtime(true);
                $inspection = $this->inspectQuestions($raw, $docs, $questions);
                $this->lastGenerationStats['timing_ms']['validation_ms'] += $this->elapsedMs($stageStartedAt);
                $questions = $this->uniqueQuestions(array_merge($questions, $inspection['valid']));
                $questions = $this->trimOverrepresentedTopics($questions, $docs);
            }
        }

        return $this->trimOverrepresentedTopics($questions, $docs);
    }

    private function targetSkillCounts(array $docs): array
    {
        $skills = $this->quizSkills($docs);

        if ($skills->count() <= 1) {
            return [];
        }

        $base = intdiv(self::TOTAL_QUESTIONS, $skills->count());
        $remainder = self::TOTAL_QUESTIONS % $skills->count();

        return $skills
            ->mapWithKeys(function (string $skill, int $index) use ($base, $remainder) {
                return [$this->normalizeSkill($skill) => [
                    'skill' => $skill,
                    'target' => $base + ($index < $remainder ? 1 : 0),
                ]];
            })
            ->all();
    }

    private function recordCorrectiveSkillAttempts(array $skills, int $maxAttemptsPerSkill): int
    {
        $attemptNumber = 1;

        foreach ($skills as $skill) {
            $normalizedSkill = $this->normalizeSkill((string) $skill);
            if ($normalizedSkill === '') {
                continue;
            }

            $this->lastGenerationStats['corrective_attempts_by_skill'][$normalizedSkill] =
                (int) ($this->lastGenerationStats['corrective_attempts_by_skill'][$normalizedSkill] ?? 0) + 1;
            $attemptNumber = max($attemptNumber, (int) $this->lastGenerationStats['corrective_attempts_by_skill'][$normalizedSkill]);

            if ($this->lastGenerationStats['corrective_attempts_by_skill'][$normalizedSkill] > $maxAttemptsPerSkill) {
                throw new RuntimeException('AI corrective response did not add enough valid grounded questions.');
            }
        }

        return $attemptNumber;
    }

    private function missingSkillCounts(array $questions, array $targets): array
    {
        $counts = collect($questions)
            ->reject(fn (array $question) => ($question['source'] ?? '') === 'resume_context')
            ->countBy(fn (array $question) => $this->normalizeSkill((string) $question['skill']));

        return collect($targets)
            ->mapWithKeys(function (array $target, string $normalizedSkill) use ($counts) {
                $missing = max(0, (int) $target['target'] - (int) ($counts[$normalizedSkill] ?? 0));

                return $missing > 0 ? [$normalizedSkill => $missing] : [];
            })
            ->all();
    }

    private function skillNameFromTargets(string $normalizedSkill, array $targets): string
    {
        return (string) ($targets[$normalizedSkill]['skill'] ?? $normalizedSkill);
    }

    private function inspectQuestions(string $raw, array $docs, array $existingQuestions = []): array
    {
        $parsed = $this->parseJson($raw);
        $questions = $parsed['questions'] ?? [];

        if (!is_array($questions)) {
            throw new RuntimeException('AI response did not contain a questions array.');
        }

        $normalized = [];
        $invalid = [];
        $seen = collect($existingQuestions)
            ->mapWithKeys(fn (array $question) => [$this->questionFingerprint((string) $question['question']) => true])
            ->all();
        $semanticSeen = collect($existingQuestions)
            ->map(fn (array $question) => $this->semanticQuestionProfile($question))
            ->filter()
            ->values()
            ->all();
        $coveredSkills = collect($docs['covered_skills'] ?? [])->values();
        $unsupportedSkills = collect($docs['unsupported_technical_skills'] ?? [])->values();
        $excludedNonTechnicalSkills = collect($docs['excluded_non_technical_skills'] ?? [])->values();
        $relevantSkills = collect($docs['relevant_technical_skills'] ?? [])
            ->merge($coveredSkills)
            ->merge($unsupportedSkills)
            ->unique()
            ->values();
        $referenceMap = collect($docs['sections'] ?? [])->keyBy('source_reference');

        foreach (array_values($questions) as $index => $question) {
            try {
                $normalizedQuestion = $this->normalizeQuestionItem(
                    $question,
                    $index,
                    $seen,
                    $coveredSkills,
                    $relevantSkills,
                    $referenceMap,
                    $unsupportedSkills,
                    $excludedNonTechnicalSkills
                );

                $semanticProfile = $this->semanticQuestionProfile($normalizedQuestion);
                if ($this->hasSemanticDuplicate($semanticProfile, $semanticSeen)) {
                    $this->lastGenerationStats['semantic_duplicates_detected']++;
                    throw new RuntimeException('AI response contains a semantic duplicate question.');
                }

                $semanticSeen[] = $semanticProfile;
                $seen[$this->questionFingerprint($normalizedQuestion['question'])] = true;
                $normalized[] = $normalizedQuestion;
            } catch (Throwable $exception) {
                $invalid[] = [
                    'index' => $index,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        if (!empty($invalid)) {
            Log::info('Interview AI question validation rejected items.', [
                'valid_count' => count($normalized),
                'invalid_count' => count($invalid),
                'invalid_errors' => collect($invalid)->pluck('error')->countBy()->all(),
            ]);
        }

        return [
            'valid' => $normalized,
            'invalid' => $invalid,
            'total' => count($questions),
        ];
    }

    private function normalizeQuestionItem(
        mixed $question,
        int $index,
        array $seen,
        $coveredSkills,
        $relevantSkills,
        $referenceMap,
        $unsupportedSkills,
        $excludedNonTechnicalSkills
    ): array {
        if (!is_array($question)) {
            throw new RuntimeException('AI response contains an invalid question item.');
        }

        $text = trim((string) ($question['question'] ?? ''));
        $options = $question['options'] ?? [];
        $answer = strtoupper(trim((string) ($question['correct_answer'] ?? '')));
        $difficulty = Str::lower(trim((string) ($question['difficulty'] ?? 'medium')));
        $source = trim((string) ($question['source'] ?? 'devdocs'));
        $skill = trim((string) ($question['skill'] ?? 'General'));
        $sourceDoc = trim((string) ($question['source_doc'] ?? 'DevDocs'));
        $sourceReference = array_key_exists('source_reference', $question)
            ? trim((string) $question['source_reference'])
            : '';

        if ($text === '' || !is_array($options) || !in_array($answer, ['A', 'B', 'C', 'D'], true)) {
            throw new RuntimeException('AI response contains an incomplete question.');
        }

        foreach (['A', 'B', 'C', 'D'] as $optionKey) {
            if (!isset($options[$optionKey]) || trim((string) $options[$optionKey]) === '') {
                throw new RuntimeException('AI response contains incomplete answer options.');
            }
        }

        if (collect(array_keys($options))->map(fn ($key) => strtoupper((string) $key))->sort()->values()->all() !== ['A', 'B', 'C', 'D']) {
            throw new RuntimeException('AI response contains unexpected answer options.');
        }

        $optionTexts = collect(['A', 'B', 'C', 'D'])
            ->map(fn (string $key) => $this->questionFingerprint((string) $options[$key]))
            ->filter()
            ->values();

        if ($optionTexts->unique()->count() !== 4) {
            throw new RuntimeException('AI response contains duplicate answer options.');
        }

        $fingerprint = $this->questionFingerprint($text);
        if (isset($seen[$fingerprint])) {
            throw new RuntimeException('AI response contains duplicate questions.');
        }

        if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            throw new RuntimeException('AI response contains invalid difficulty.');
        }

        if ($source === 'resume_context') {
            $sourceReference = '';
            $sourceDoc = 'Resume Context';
        } elseif ($source === 'ai_knowledge') {
            if ($sourceReference !== '') {
                throw new RuntimeException('AI knowledge question must not include a DevDocs source reference.');
            }

            if ($coveredSkills->contains(fn (string $covered) => $this->sameSkill($skill, $covered))) {
                throw new RuntimeException('AI knowledge question used a DevDocs-covered skill.');
            }

            if (!$unsupportedSkills->contains(fn (string $unsupported) => $this->sameSkill($skill, $unsupported))) {
                throw new RuntimeException('AI knowledge question used a skill outside unsupported technical job skills.');
            }

            if ($excludedNonTechnicalSkills->contains(fn (string $excluded) => $this->sameSkill($skill, $excluded))) {
                throw new RuntimeException('AI knowledge question used an excluded non-technical skill.');
            }

            $sourceDoc = 'AI Knowledge';
        } else {
            if ($source !== 'devdocs') {
                throw new RuntimeException('AI response contains an invalid question source.');
            }

            $source = 'devdocs';
            $section = $referenceMap->get($sourceReference);

            if (!$section) {
                throw new RuntimeException('AI response cited a DevDocs source that was not retrieved.');
            }

            if (!$this->sameSkill($skill, (string) $section['skill'])) {
                throw new RuntimeException('AI response skill does not match the cited DevDocs context.');
            }

            $sourceDoc = (string) $section['doc_name'];

            if (!$coveredSkills->contains(fn (string $covered) => $this->sameSkill($skill, $covered))) {
                throw new RuntimeException('AI response used an uncovered skill as a DevDocs question.');
            }

            $this->validateTechnologyGrounding($text, $skill, $section);
        }

        if ($source !== 'resume_context' && !$relevantSkills->contains(fn (string $allowed) => $this->sameSkill($skill, $allowed))) {
            throw new RuntimeException('AI response used a skill outside the relevant job skill set.');
        }

        return [
            'id' => $index + 1,
            'question' => $text,
            'options' => [
                'A' => trim((string) $options['A']),
                'B' => trim((string) $options['B']),
                'C' => trim((string) $options['C']),
                'D' => trim((string) $options['D']),
            ],
            'correct_answer' => $answer,
            'difficulty' => $difficulty,
            'skill' => $skill,
            'topic' => $this->topicForQuestion($text, $skill, $sourceReference),
            'source' => $source,
            'source_doc' => $sourceDoc,
            'source_reference' => $sourceReference,
        ];
    }

    private function validateTechnologyGrounding(string $question, string $skill, array $section): void
    {
        $questionText = Str::lower($question);
        $technologyTerms = [
            'Laravel' => ['laravel', 'artisan', 'eloquent', 'blade', 'migration', 'middleware'],
            'PHP' => ['php', 'mysqli', 'pdo', 'composer'],
            'MySQL' => ['mysql'],
            'REST APIs' => ['rest', 'http method', 'http methods', 'status code', 'status codes'],
            'Git' => ['git', 'commit', 'branch', 'merge', 'pull', 'push'],
        ];

        foreach ($technologyTerms as $technology => $terms) {
            $mentionsTechnology = collect($terms)->contains(fn (string $term) => str_contains($questionText, $term));

            if (!$mentionsTechnology) {
                continue;
            }

            if (!$this->sameSkill($skill, $technology)) {
                throw new RuntimeException("AI response falsely grounded a {$technology} question under another skill.");
            }
        }
    }

    private function uniqueQuestions(array $questions): array
    {
        return collect($questions)
            ->unique(fn (array $question) => $this->questionFingerprint((string) $question['question']))
            ->values()
            ->map(fn (array $question, int $index) => array_merge($question, ['id' => $index + 1]))
            ->all();
    }

    private function selectBalancedQuestions(array $questions, array $docs): array
    {
        $questions = collect($this->uniqueQuestions($questions));

        if ($questions->count() <= self::TOTAL_QUESTIONS) {
            return $questions
                ->values()
                ->map(fn (array $question, int $index) => array_merge($question, ['id' => $index + 1]))
                ->all();
        }

        $coveredSkills = $this->quizSkills($docs);
        $selected = collect();
        $targetPerSkill = max(1, (int) floor(self::TOTAL_QUESTIONS / max(1, $coveredSkills->count())));

        foreach ($coveredSkills as $skill) {
            $skillQuestions = $questions
                ->filter(fn (array $question) => $this->sameSkill((string) $question['skill'], (string) $skill));

            $selected = $selected->merge(
                $this->preferTopicDiversity($skillQuestions)
                    ->take($targetPerSkill)
            );
        }

        if ($selected->count() < self::TOTAL_QUESTIONS) {
            $selected = $selected->merge(
                $questions
                    ->reject(fn (array $question) => $selected->contains(fn (array $selectedQuestion) => $this->questionFingerprint($selectedQuestion['question']) === $this->questionFingerprint($question['question'])))
                    ->take(self::TOTAL_QUESTIONS - $selected->count())
            );
        }

        $final = $selected
            ->take(self::TOTAL_QUESTIONS)
            ->values()
            ->map(fn (array $question, int $index) => array_merge($question, ['id' => $index + 1]))
            ->all();

        $this->lastGenerationStats['topic_diversity'] = $this->topicDistribution($final);

        return $final;
    }

    private function skillsNeedingQuestions(array $questions, array $docs, int $needed): array
    {
        $coveredSkills = $this->quizSkills($docs);
        if ($coveredSkills->isEmpty()) {
            return [];
        }

        $counts = collect($questions)->countBy(fn (array $question) => $this->normalizeSkill((string) $question['skill']));

        return $coveredSkills
            ->sortBy(fn (string $skill) => (int) ($counts[$this->normalizeSkill($skill)] ?? 0))
            ->take(max(1, min($needed, $coveredSkills->count())))
            ->values()
            ->all();
    }

    private function questionFingerprint(string $question): string
    {
        return Str::lower(preg_replace('/[^a-z0-9]+/i', ' ', $question) ?? $question);
    }

    private function semanticQuestionProfile(array $question): array
    {
        $skill = $this->normalizeSkill((string) ($question['skill'] ?? ''));
        $text = (string) ($question['question'] ?? '');
        $answerKey = strtoupper((string) ($question['correct_answer'] ?? ''));
        $answerText = (string) ($question['options'][$answerKey] ?? '');
        $topic = (string) ($question['topic'] ?? $this->topicForQuestion(
            $text,
            (string) ($question['skill'] ?? ''),
            (string) ($question['source_reference'] ?? '')
        ));

        return [
            'skill' => $skill,
            'topic' => $topic,
            'critical_concepts' => $this->criticalConcepts($text . ' ' . $answerText),
            'terms' => $this->meaningTerms($text . ' ' . $answerText),
        ];
    }

    private function usedConceptsBySkill(array $questions): array
    {
        return collect($questions)
            ->map(fn (array $question) => $this->semanticQuestionProfile($question))
            ->filter(fn (array $profile) => ($profile['skill'] ?? '') !== '')
            ->groupBy('skill')
            ->map(function ($profiles) {
                return $profiles
                    ->map(fn (array $profile) => [
                        'topic' => (string) ($profile['topic'] ?? 'general'),
                        'critical_concepts' => array_values($profile['critical_concepts'] ?? []),
                        'keywords' => array_slice(array_values($profile['terms'] ?? []), 0, 8),
                    ])
                    ->unique(fn (array $concept) => $concept['topic'] . ':' . implode(',', $concept['critical_concepts']) . ':' . implode(',', array_slice($concept['keywords'], 0, 5)))
                    ->values()
                    ->all();
            })
            ->all();
    }

    private function semanticFingerprintsBySkill(array $questions, array $skills): array
    {
        $skillMap = collect($skills)
            ->mapWithKeys(fn (string $skill) => [$this->normalizeSkill($skill) => $skill])
            ->all();

        return collect($questions)
            ->map(fn (array $question) => $this->semanticQuestionProfile($question))
            ->filter(fn (array $profile) => isset($skillMap[$profile['skill'] ?? '']))
            ->groupBy('skill')
            ->map(function ($profiles) {
                return $profiles
                    ->map(fn (array $profile) => implode(' ', array_slice($profile['terms'] ?? [], 0, 10)))
                    ->filter()
                    ->unique()
                    ->take(20)
                    ->values()
                    ->all();
            })
            ->all();
    }

    private function hasSemanticDuplicate(array $candidate, array $existingProfiles): bool
    {
        foreach ($existingProfiles as $existing) {
            if (($candidate['skill'] ?? '') !== ($existing['skill'] ?? '')) {
                continue;
            }

            $candidateTerms = $candidate['terms'] ?? [];
            $existingTerms = $existing['terms'] ?? [];
            $intersection = array_values(array_intersect($candidateTerms, $existingTerms));
            $union = array_values(array_unique(array_merge($candidateTerms, $existingTerms)));

            if (count($union) === 0) {
                continue;
            }

            $sameTopic = ($candidate['topic'] ?? '') === ($existing['topic'] ?? '');
            $jaccard = count($intersection) / count($union);
            $criticalOverlap = array_intersect($candidate['critical_concepts'] ?? [], $existing['critical_concepts'] ?? []);

            if (!empty($criticalOverlap)) {
                return true;
            }

            if ($jaccard >= 0.82 && count($intersection) >= 5) {
                return true;
            }

            if ($sameTopic && $jaccard >= 0.62 && count($intersection) >= 5) {
                return true;
            }

            if ($sameTopic && count($intersection) >= 2 && $this->sharesCriticalConceptPair($candidateTerms, $existingTerms)) {
                return true;
            }
        }

        return false;
    }

    private function criticalConcepts(string $text): array
    {
        $normalized = Str::lower($text);
        $normalized = str_replace(['$this', 'php 8.0', 'not defined', 'not set'], ['this', 'php8', 'undefined', 'undefined'], $normalized);
        $concepts = [];

        if (str_contains($normalized, 'this') && str_contains($normalized, 'undefined')) {
            $concepts[] = str_contains($normalized, 'php8') ? 'php8_undefined_this' : 'undefined_this';
        }

        if (str_contains($normalized, 'autosetupmerge') && str_contains($normalized, 'branch')) {
            $concepts[] = 'git_branch_autosetupmerge';
        }

        if (str_contains($normalized, 'status') && str_contains($normalized, 'code')) {
            $concepts[] = 'http_status_code';
        }

        if (str_contains($normalized, 'pdo') && str_contains($normalized, 'prepare')) {
            $concepts[] = 'php_pdo_prepare';
        }

        return $concepts;
    }

    private function sharesCriticalConceptPair(array $candidateTerms, array $existingTerms): bool
    {
        $candidate = array_flip($candidateTerms);
        $existing = array_flip($existingTerms);

        foreach ([
            ['this', 'undefined'],
            ['php8', 'undefined'],
            ['autosetupmerge', 'branch'],
            ['status', 'code'],
            ['pdo', 'prepare'],
        ] as $pair) {
            if (isset($candidate[$pair[0]], $candidate[$pair[1]], $existing[$pair[0]], $existing[$pair[1]])) {
                return true;
            }
        }

        return false;
    }

    private function meaningTerms(string $text): array
    {
        $normalized = Str::lower($text);
        $normalized = str_replace(
            ['$this', 'php 8.0', 'php8', 'not defined', 'not set', 'branch.autosetupmerge'],
            ['this', 'php8', 'php8', 'undefined', 'undefined', 'autosetupmerge'],
            $normalized
        );
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
        $stopWords = array_flip([
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'before', 'by', 'can', 'does', 'for', 'from',
            'happen', 'happens', 'how', 'in', 'is', 'it', 'of', 'on', 'or', 'should', 'the', 'to',
            'what', 'when', 'which', 'why', 'with', 'would', 'candidate', 'question', 'practical',
            'concept', 'documentation', 'know', 'item', 'supported',
            'correct', 'option', 'distractor', 'unrelated', 'incorrect', 'shortcut', 'claim',
        ]);

        return collect(explode(' ', $normalized))
            ->map(fn (string $term) => $this->stemMeaningTerm($term))
            ->filter(fn (string $term) => strlen($term) > 2 && !isset($stopWords[$term]))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function stemMeaningTerm(string $term): string
    {
        $synonyms = [
            'undefined' => 'undefined',
            'undefine' => 'undefined',
            'unbound' => 'undefined',
            'missing' => 'undefined',
            'exception' => 'exception',
            'exceptions' => 'exception',
            'error' => 'exception',
            'errors' => 'exception',
            'class' => 'oop',
            'classes' => 'oop',
            'object' => 'oop',
            'objects' => 'oop',
            'method' => 'method',
            'methods' => 'method',
            'status' => 'status',
            'statuses' => 'status',
            'response' => 'response',
            'responses' => 'response',
            'request' => 'request',
            'requests' => 'request',
            'route' => 'routing',
            'routes' => 'routing',
            'commit' => 'commit',
            'commits' => 'commit',
            'branch' => 'branch',
            'branches' => 'branch',
            'checkout' => 'checkout',
            'merge' => 'merge',
            'merges' => 'merge',
            'conflict' => 'conflict',
            'conflicts' => 'conflict',
        ];

        $term = $synonyms[$term] ?? $term;

        foreach (['ing', 'ed', 'es', 's'] as $suffix) {
            if (strlen($term) > 5 && str_ends_with($term, $suffix)) {
                return substr($term, 0, -strlen($suffix));
            }
        }

        return $term;
    }

    private function topicForQuestion(string $question, string $skill, string $sourceReference = ''): string
    {
        $text = Str::lower($question . ' ' . $sourceReference);
        $skill = $this->normalizeSkill($skill);
        $topics = [
            'git' => [
                'checkout' => ['checkout', 'switch'],
                'commit' => ['commit', 'snapshot', 'staged'],
                'merge' => ['merge', 'conflict'],
                'pull_push' => ['pull', 'push', 'fetch', 'remote'],
                'branch' => ['branch', 'autosetupmerge'],
            ],
            'php' => [
                'pdo' => ['pdo', 'prepare', 'database'],
                'oop' => ['class', 'object', 'method', 'this', 'property'],
                'exceptions' => ['exception', 'error', 'throw', 'catch'],
                'types' => ['type', 'array', 'string', 'integer'],
                'functions' => ['function', 'parameter', 'return'],
            ],
            'rest apis' => [
                'methods' => ['method', 'get', 'post', 'put', 'delete'],
                'status_codes' => ['status', 'code', '200', '404', '500'],
                'headers' => ['header', 'content type', 'authorization'],
                'request_response' => ['request', 'response', 'body'],
                'statelessness' => ['stateless', 'state'],
            ],
            'laravel' => [
                'auth' => ['auth', 'authentication', 'authorize'],
                'requests' => ['request', 'input'],
                'validation' => ['validation', 'validate', 'rules'],
                'routing' => ['route', 'routing'],
                'query_builder' => ['query', 'database', 'builder'],
                'middleware' => ['middleware'],
            ],
        ];

        foreach ($topics[$skill] ?? [] as $topic => $terms) {
            foreach ($terms as $term) {
                if (str_contains($text, $term)) {
                    return $topic;
                }
            }
        }

        return 'general';
    }

    private function supportedTopicsForSkill(string $skill, array $docs): array
    {
        return collect($docs['sections'] ?? [])
            ->filter(fn (array $section) => $this->sameSkill($skill, (string) $section['skill']))
            ->map(fn (array $section) => $this->topicForQuestion(
                (string) ($section['text'] ?? ''),
                (string) $section['skill'],
                (string) ($section['source_reference'] ?? '')
            ))
            ->reject(fn (string $topic) => $topic === 'general')
            ->unique()
            ->values()
            ->all();
    }

    private function preferTopicDiversity($questions)
    {
        $groups = $questions
            ->groupBy(fn (array $question) => (string) ($question['topic'] ?? 'general'))
            ->sortByDesc(fn ($group) => $group->count())
            ->values();
        $ordered = collect();
        $max = $groups->max(fn ($group) => $group->count()) ?? 0;

        for ($index = 0; $index < $max; $index++) {
            foreach ($groups as $group) {
                if (isset($group[$index])) {
                    $ordered->push($group[$index]);
                }
            }
        }

        return $ordered;
    }

    private function topicDistribution(array $questions): array
    {
        return collect($questions)
            ->groupBy('skill')
            ->map(fn ($group) => $group->countBy(fn (array $question) => (string) ($question['topic'] ?? 'general'))->all())
            ->all();
    }

    private function recordAiCall(string $prompt, string $provider): void
    {
        $this->lastGenerationStats['total_ai_calls']++;
        $this->lastGenerationStats['estimated_tokens_per_call'][] = $this->estimateTokens($prompt);
        $this->lastGenerationStats['provider_calls'][] = [
            'provider' => $provider,
            'estimated_tokens' => $this->estimateTokens($prompt),
        ];

        if (str_contains($prompt, 'Corrective generation only.')) {
            $this->lastGenerationStats['corrective_calls_by_provider'][$provider] =
                (int) ($this->lastGenerationStats['corrective_calls_by_provider'][$provider] ?? 0) + 1;
        }
    }

    private function completionBudget(int $questionCount): int
    {
        return $this->completionBudgetForProvider($questionCount, 'groq');
    }

    private function completionBudgetForProvider(int $questionCount, string $provider): int
    {
        $configuredMax = (int) config('services.groq.interview_max_completion_tokens', 3000);

        if ($provider === 'gemini') {
            $configuredMax = (int) config('services.gemini.interview_max_output_tokens', 8192);
        }

        if ($questionCount >= self::TOTAL_QUESTIONS) {
            return $configuredMax;
        }

        $budget = 800 + ($questionCount * 260);

        return max(650, min($configuredMax, $budget));
    }

    private function isRateLimit(Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), 'rate_limit_exceeded')
            || str_contains(Str::lower($exception->getMessage()), 'rate limit');
    }

    private function shouldFallbackToSecondary(Throwable $exception): bool
    {
        return in_array($this->failureCategory($exception), [
            'rate_limit',
            'timeout',
            'provider_unavailable',
            'provider_generation',
            'network',
            'json_validation',
        ], true);
    }

    private function primaryProvider(): string
    {
        return config('services.ai_provider', 'groq') === 'gemini' ? 'gemini' : 'groq';
    }

    private function secondaryProvider(string $primaryProvider): string
    {
        return $primaryProvider === 'gemini' ? 'groq' : 'gemini';
    }

    private function recordProviderFailure(string $provider, Throwable $exception): void
    {
        $category = $this->failureCategory($exception);

        if ($provider === 'gemini') {
            $this->lastGenerationStats['gemini_failure_category'] = $category;
        } else {
            $this->lastGenerationStats['groq_failure_category'] = $category;
            $this->lastGenerationStats['rate_limit_occurred'] = $this->lastGenerationStats['rate_limit_occurred'] || $category === 'rate_limit';

            if ($category === 'rate_limit') {
                $this->lastGenerationStats['groq_disabled_for_request'] = true;
                $this->lastGenerationStats['provider_switch_reason'] = 'groq_rate_limit';
            }
        }
    }

    private function failureCategory(Throwable $exception): string
    {
        $message = Str::lower($exception->getMessage());

        if ($this->isRateLimit($exception) || str_contains($message, 'token limit') || str_contains($message, 'tokens per day')) {
            return 'rate_limit';
        }

        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'timeout';
        }

        if ($this->isStructuredOutputGenerationFailure($exception)) {
            return 'provider_generation';
        }

        if ($this->isJsonValidationFailure($exception) || str_contains($message, 'not valid json')) {
            return 'json_validation';
        }

        if (str_contains($message, '503') || str_contains($message, 'unavailable') || str_contains($message, 'service unavailable')) {
            return 'provider_unavailable';
        }

        if (str_contains($message, 'connection') || str_contains($message, 'network') || str_contains($message, 'could not resolve')) {
            return 'network';
        }

        return 'provider_error';
    }

    private function isJsonValidationFailure(Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), 'json_validate_failed')
            || str_contains(Str::lower($exception->getMessage()), 'failed to validate json')
            || str_contains(Str::lower($exception->getMessage()), 'not valid json');
    }

    private function isStructuredOutputGenerationFailure(Throwable $exception): bool
    {
        $message = Str::lower($exception->getMessage());

        return str_contains($message, 'failed to generate json')
            || str_contains($message, 'failed_generation')
            || str_contains($message, 'structured output generation failure')
            || str_contains($message, 'response format generation failure');
    }

    private function backoffForRateLimit(Throwable $exception): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $message = $exception->getMessage();
        $seconds = 2;

        if (preg_match('/try again in ([0-9.]+)s?/i', $message, $matches)) {
            $seconds = (int) ceil((float) $matches[1]);
        }

        sleep(min(max($seconds, 1), 60));
    }

    private function validateBalancedCoverage(array $questions, array $coveredSkills): void
    {
        $skills = collect($coveredSkills)->unique()->values();
        $technicalQuestions = collect($questions)
            ->reject(fn (array $question) => ($question['source'] ?? '') === 'resume_context');

        if ($skills->count() <= 1 || $technicalQuestions->count() < $skills->count() * 2) {
            return;
        }

        $counts = $technicalQuestions->countBy(fn (array $question) => $this->normalizeSkill((string) $question['skill']));
        $maxAllowed = max(6, (int) ceil($technicalQuestions->count() / $skills->count()) + 2);

        foreach ($skills as $skill) {
            $count = (int) ($counts[$this->normalizeSkill($skill)] ?? 0);

            if ($count < 2) {
                throw new RuntimeException('AI response did not provide balanced coverage for technical skills.');
            }

            if ($count > $maxAllowed) {
                throw new RuntimeException('AI response overrepresented one technical skill.');
            }
        }
    }

    private function quizSkills(array $docs)
    {
        return collect($docs['covered_skills'] ?? [])
            ->merge($docs['unsupported_technical_skills'] ?? [])
            ->filter()
            ->unique(fn (string $skill) => $this->normalizeSkill($skill))
            ->values();
    }

    private function skillDistribution(array $questions): array
    {
        return collect($questions)
            ->countBy(fn (array $question) => (string) ($question['skill'] ?? 'unknown'))
            ->all();
    }

    private function sameSkill(string $left, string $right): bool
    {
        return $this->normalizeSkill($left) === $this->normalizeSkill($right);
    }

    private function normalizeSkill(string $skill): string
    {
        $normalized = Str::lower(trim($skill));

        return match (true) {
            str_contains($normalized, 'rest') || str_contains($normalized, 'http') || str_contains($normalized, 'api') => 'rest apis',
            str_contains($normalized, 'laravel') => 'laravel',
            str_contains($normalized, 'mysql') => 'mysql',
            str_contains($normalized, 'php') => 'php',
            str_contains($normalized, 'git') => 'git',
            default => preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?: $normalized,
        };
    }

    private function sameDoc(string $left, string $right): bool
    {
        return Str::lower(trim($left)) === Str::lower(trim($right));
    }

    private function parseJson(string $raw): array
    {
        $cleaned = trim((string) preg_replace('/```json|```/i', '', $raw));
        $parsed = json_decode($cleaned, true);

        if (is_array($parsed)) {
            return $parsed;
        }

        if (preg_match('/\{.*\}/s', $cleaned, $matches)) {
            $parsed = json_decode($matches[0], true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        throw new RuntimeException('AI response was not valid JSON.');
    }

    private function context(JobPost $job, ?Student $student): array
    {
        $resume = $student
            ? Resume::where('student_id', $student->id)->latest()->first()
            : null;

        return [
            'job_title' => $job->title,
            'job_description' => $job->description,
            'job_requirements' => $job->requirements,
            'job_skills' => $job->skills->pluck('name')->filter()->values()->all(),
            'job_level' => $job->level,
            'student_major' => $student?->major,
            'student_skills' => $student?->skills?->pluck('name')->filter()->values()->all() ?? [],
            'resume_projects' => $resume?->projects ?? [],
            'resume_experience' => $resume?->experience ?? [],
        ];
    }

    private function minimalJobContext(array $context): array
    {
        return [
            'job_title' => $context['job_title'],
            'job_description' => Str::limit((string) $context['job_description'], 700, ''),
            'job_requirements' => Str::limit((string) $context['job_requirements'], 700, ''),
            'job_skills' => $context['job_skills'],
            'job_level' => $context['job_level'] ?? null,
            'student_major' => $context['student_major'],
            'student_skills' => $context['student_skills'],
        ];
    }

    private function minimalResumeContext(array $context): array
    {
        return [
            'projects' => collect($context['resume_projects'] ?? [])
                ->take(3)
                ->map(fn ($project) => [
                    'name' => is_array($project) ? ($project['name'] ?? $project['title'] ?? null) : null,
                    'description' => is_array($project) ? Str::limit((string) ($project['description'] ?? ''), 300, '') : null,
                    'technologies' => is_array($project) ? ($project['technologies'] ?? $project['skills'] ?? []) : [],
                ])
                ->filter(fn (array $project) => !empty($project['name']))
                ->values()
                ->all(),
            'experience' => collect($context['resume_experience'] ?? [])
                ->take(2)
                ->map(fn ($experience) => [
                    'title' => is_array($experience) ? ($experience['title'] ?? $experience['position'] ?? null) : null,
                    'description' => is_array($experience) ? Str::limit((string) ($experience['description'] ?? ''), 300, '') : null,
                ])
                ->filter(fn (array $experience) => !empty($experience['title']) || !empty($experience['description']))
                ->values()
                ->all(),
        ];
    }
}
