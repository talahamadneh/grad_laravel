<?php

namespace App\Services;

use App\Models\InterviewQuizAttempt;
use App\Models\JobPost;
use App\Models\SavedJob;
use App\Models\Student;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class InterviewQuizAttemptService
{
    public function __construct(
        private InterviewQuestionGenerationService $questionGenerator,
    ) {
    }

    public function start(JobPost $job, Student $student): array
    {
        $this->ensureSavedJob($job, $student);

        $cacheKey = $this->openAttemptCacheKey($student->id, $job->id);
        $cachedAttemptId = Cache::get($cacheKey);

        if ($cachedAttemptId) {
            $attempt = InterviewQuizAttempt::where('student_id', $student->id)
                ->where('job_id', $job->id)
                ->where('status', InterviewQuizAttempt::STATUS_OPEN)
                ->find($cachedAttemptId);

            if ($attempt) {
                return $this->attemptResponse($attempt, fromCache: true);
            }
        }

        $attempt = InterviewQuizAttempt::where('student_id', $student->id)
            ->where('job_id', $job->id)
            ->where('status', InterviewQuizAttempt::STATUS_OPEN)
            ->latest('id')
            ->first();

        if ($attempt) {
            Cache::put($cacheKey, $attempt->id, now()->addHours(6));

            return $this->attemptResponse($attempt);
        }

        return $this->createAttempt($job, $student);
    }

    public function retake(JobPost $job, Student $student): array
    {
        $this->ensureSavedJob($job, $student);

        $previousQuestions = InterviewQuizAttempt::where('student_id', $student->id)
            ->where('job_id', $job->id)
            ->latest('id')
            ->take(3)
            ->get()
            ->flatMap(fn (InterviewQuizAttempt $attempt) => collect($attempt->questions ?? [])->pluck('question'))
            ->filter()
            ->unique()
            ->take(60)
            ->values()
            ->all();

        // Generate and validate the replacement before changing the current
        // attempt. A provider failure must not leave the student without an
        // open quiz to continue.
        try {
            $result = $this->questionGenerator->generate($job, $student, $previousQuestions);
        } catch (Throwable $exception) {
            Log::warning('Interview retake could not avoid previous questions; retrying without exclusions.', [
                'student_id' => $student->id,
                'job_id' => $job->id,
                'error' => $exception->getMessage(),
            ]);

            $result = $this->questionGenerator->generate($job, $student);
            $result['metadata']['retake_avoidance_fallback_used'] = true;
        }
        $questions = $this->shuffleAnswerOptions($result['questions']);

        $attempt = DB::transaction(function () use ($job, $student, $questions) {
            InterviewQuizAttempt::where('student_id', $student->id)
                ->where('job_id', $job->id)
                ->where('status', InterviewQuizAttempt::STATUS_OPEN)
                ->update([
                    'status' => InterviewQuizAttempt::STATUS_ABANDONED,
                    'answers' => null,
                    'score' => null,
                    'completed_at' => now(),
                ]);

            return $this->persistAttempt($job, $student, $questions);
        });

        $cacheKey = $this->openAttemptCacheKey($student->id, $job->id);
        Cache::forget($cacheKey);
        Cache::put($cacheKey, $attempt->id, now()->addHours(6));

        return $this->attemptResponse($attempt, metadata: $result['metadata']);
    }

    public function submit(InterviewQuizAttempt $attempt, Student $student, array $answers): array
    {
        if ((int) $attempt->student_id !== (int) $student->id) {
            throw new RuntimeException('You are not allowed to submit this quiz attempt.');
        }

        if ($attempt->status === InterviewQuizAttempt::STATUS_COMPLETED) {
            throw new RuntimeException('This quiz attempt has already been submitted.');
        }

        $questions = collect($attempt->questions ?? []);
        if ($questions->isEmpty()) {
            throw new RuntimeException('This quiz attempt has no questions.');
        }

        $normalizedAnswers = collect($answers)
            ->mapWithKeys(fn ($answer, $id) => [(string) $id => strtoupper(trim((string) $answer))])
            ->all();

        $correctCount = 0;
        $results = $questions->map(function (array $question) use ($normalizedAnswers, &$correctCount) {
            $id = (string) ($question['id'] ?? '');
            $studentAnswer = $normalizedAnswers[$id] ?? null;
            $correctAnswer = strtoupper(trim((string) ($question['correct_answer'] ?? '')));
            $isCorrect = $studentAnswer !== null && $studentAnswer === $correctAnswer;

            if ($isCorrect) {
                $correctCount++;
            }

            return [
                'id' => $question['id'] ?? null,
                'question' => $question['question'] ?? '',
                'options' => $question['options'] ?? [],
                'difficulty' => $question['difficulty'] ?? null,
                'skill' => $question['skill'] ?? null,
                'student_answer' => $studentAnswer,
                'correct_answer' => $correctAnswer,
                'is_correct' => $isCorrect,
            ];
        })->values()->all();

        $total = $questions->count();
        $percentage = (int) round(($correctCount / $total) * 100);

        $attempt->update([
            'answers' => $normalizedAnswers,
            'score' => $percentage,
            'status' => InterviewQuizAttempt::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        Cache::forget($this->openAttemptCacheKey($student->id, (int) $attempt->job_id));

        return [
            'attempt_id' => $attempt->id,
            'job_id' => $attempt->job_id,
            'score' => $percentage,
            'percentage' => $percentage,
            'correct_count' => $correctCount,
            'total_questions' => $total,
            'results' => $results,
        ];
    }

    public function history(JobPost $job, Student $student): array
    {
        $this->ensureSavedJob($job, $student);

        return InterviewQuizAttempt::where('student_id', $student->id)
            ->where('job_id', $job->id)
            ->latest('id')
            ->get()
            ->map(fn (InterviewQuizAttempt $attempt) => [
                'attempt_id' => $attempt->id,
                'job_id' => $attempt->job_id,
                'status' => $attempt->status,
                'score' => $attempt->score,
                'percentage' => $attempt->score,
                'total_questions' => count($attempt->questions ?? []),
                'started_at' => optional($attempt->started_at)->toISOString(),
                'completed_at' => optional($attempt->completed_at)->toISOString(),
            ])
            ->values()
            ->all();
    }

    private function createAttempt(JobPost $job, Student $student, array $avoidQuestions = []): array
    {
        $result = $this->questionGenerator->generate($job, $student, $avoidQuestions);
        $questions = $this->shuffleAnswerOptions($result['questions']);
        $attempt = $this->persistAttempt($job, $student, $questions);

        Cache::put($this->openAttemptCacheKey($student->id, $job->id), $attempt->id, now()->addHours(6));

        return $this->attemptResponse($attempt, metadata: $result['metadata']);
    }

    private function persistAttempt(JobPost $job, Student $student, array $questions): InterviewQuizAttempt
    {
        return InterviewQuizAttempt::create([
            'student_id' => $student->id,
            'job_id' => $job->id,
            'questions' => $questions,
            'answers' => null,
            'score' => null,
            'status' => InterviewQuizAttempt::STATUS_OPEN,
            'started_at' => now(),
            'completed_at' => null,
        ]);
    }

    private function attemptResponse(InterviewQuizAttempt $attempt, array $metadata = [], bool $fromCache = false): array
    {
        return [
            'attempt_id' => $attempt->id,
            'job_id' => $attempt->job_id,
            'status' => $attempt->status,
            'started_at' => optional($attempt->started_at)->toISOString(),
            'questions' => $this->publicQuestions($attempt->questions ?? []),
            'metadata' => array_merge($metadata, [
                'attempt_source' => empty($metadata) ? 'existing_open_attempt' : 'new_attempt',
                'from_cache' => $fromCache,
            ]),
        ];
    }

    private function publicQuestions(array $questions): array
    {
        return collect($questions)
            ->map(fn (array $question) => [
                'id' => $question['id'] ?? null,
                'question' => $question['question'] ?? '',
                'options' => $question['options'] ?? [],
                'difficulty' => $question['difficulty'] ?? null,
                'skill' => $question['skill'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function shuffleAnswerOptions(array $questions): array
    {
        $targetKeys = $this->balancedAnswerKeys(count($questions));

        return collect($questions)
            ->values()
            ->map(function (array $question, int $index) use ($targetKeys) {
                $options = $question['options'] ?? [];
                $currentAnswerKey = strtoupper(trim((string) ($question['correct_answer'] ?? '')));
                $correctText = isset($options[$currentAnswerKey]) ? (string) $options[$currentAnswerKey] : null;

                if (!$correctText || count($options) !== 4) {
                    return $question;
                }

                $targetCorrectKey = $targetKeys[$index] ?? $this->optionKeys()[array_rand($this->optionKeys())];
                $distractors = collect($options)
                    ->reject(fn ($value, string $key) => strtoupper($key) === $currentAnswerKey)
                    ->values()
                    ->map(fn ($value) => (string) $value)
                    ->all();

                shuffle($distractors);

                $rebuiltOptions = [];
                foreach ($this->optionKeys() as $key) {
                    $rebuiltOptions[$key] = $key === $targetCorrectKey
                        ? $correctText
                        : (string) array_shift($distractors);
                }

                $question['options'] = $rebuiltOptions;
                $question['correct_answer'] = $targetCorrectKey;

                return $question;
            })
            ->all();
    }

    private function balancedAnswerKeys(int $questionCount): array
    {
        $keys = [];

        for ($index = 0; $index < $questionCount; $index++) {
            $keys[] = $this->optionKeys()[$index % 4];
        }

        shuffle($keys);

        return $keys;
    }

    private function optionKeys(): array
    {
        return ['A', 'B', 'C', 'D'];
    }

    private function ensureSavedJob(JobPost $job, Student $student): void
    {
        $isSaved = SavedJob::where('student_id', $student->id)
            ->where('job_post_id', $job->id)
            ->exists();

        if (!$isSaved) {
            throw new RuntimeException('Please save this job before starting the interview quiz.');
        }
    }

    private function openAttemptCacheKey(int $studentId, int $jobId): string
    {
        return "interview_quiz:open_attempt:student:{$studentId}:job:{$jobId}";
    }
}
