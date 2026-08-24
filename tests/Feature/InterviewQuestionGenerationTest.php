<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobPost;
use App\Models\InterviewQuizAttempt;
use App\Models\Resume;
use App\Models\Skill;
use App\Models\Student;
use App\Models\User;
use App\Services\DevDocsRetrievalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InterviewQuestionGenerationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        foreach (['interview_quiz_attempts', 'resumes', 'saved_jobs', 'student_skills', 'job_skills', 'job_posts', 'skills', 'companies', 'students', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('Student');
            $table->string('status')->default('Active');
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('major')->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('company_name');
            $table->string('approval_status')->default('Approved');
            $table->timestamps();
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('job_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->string('level')->nullable();
            $table->string('status')->default('Open');
            $table->timestamps();
        });

        Schema::create('job_skills', function (Blueprint $table) {
            $table->foreignId('job_post_id');
            $table->foreignId('skill_id');
            $table->primary(['job_post_id', 'skill_id']);
        });

        Schema::create('student_skills', function (Blueprint $table) {
            $table->foreignId('student_id');
            $table->foreignId('skill_id');
            $table->primary(['student_id', 'skill_id']);
        });

        Schema::create('saved_jobs', function (Blueprint $table) {
            $table->foreignId('student_id');
            $table->foreignId('job_post_id');
            $table->primary(['student_id', 'job_post_id']);
        });

        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->string('title')->nullable();
            $table->json('experience')->nullable();
            $table->json('projects')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();
        });

        Schema::create('interview_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->foreignId('job_id');
            $table->json('questions');
            $table->json('answers')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        config([
            'services.interview_external_ai.enabled' => true,
            'services.groq.keys' => ['test-groq-key'],
            'services.devdocs.base_url' => 'https://devdocs.io',
            'services.devdocs.documents_url' => 'https://documents.devdocs.io',
            'services.devdocs.max_docs' => 4,
            'services.devdocs.cache_ttl' => 60,
        ]);
    }

    public function test_generates_backend_questions_from_devdocs_and_ai(): void
    {
        [$user, $job] = $this->studentAndJob('Backend Developer', ['PHP', 'MySQL', 'REST API']);
        $this->fakeSuccessfulGeneration();

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $response->assertJsonPath('metadata.generation_source', 'devdocs_grounded_ai');
        $this->assertCount(20, $response->json('questions'));
    }

    public function test_generates_frontend_questions_from_devdocs_and_ai(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['React', 'JavaScript', 'CSS']);
        $this->fakeSuccessfulGeneration();

        $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->assertJsonPath('metadata.documentation_source', 'devdocs');
    }

    public function test_generates_database_role_questions_from_devdocs_and_ai(): void
    {
        [$user, $job] = $this->studentAndJob('Database Developer', ['MySQL', 'SQL']);
        $this->fakeSuccessfulGeneration();

        $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->assertJsonCount(20, 'questions');
    }

    public function test_generates_mixed_skill_questions_from_devdocs_and_ai(): void
    {
        [$user, $job] = $this->studentAndJob('Full Stack Developer', ['React', 'PHP', 'Docker', 'Git']);
        $this->fakeSuccessfulGeneration();

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $this->assertGreaterThanOrEqual(4, $response->json('metadata.context_sections'));
    }

    public function test_unusual_title_uses_common_skills_instead_of_free_generation(): void
    {
        [$user, $job] = $this->studentAndJob('Digital Product Builder', ['JavaScript', 'HTTP']);
        $this->fakeSuccessfulGeneration();

        $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->assertJsonPath('metadata.covered_skills.0', 'JavaScript');
    }

    public function test_unsupported_skills_return_clean_error_without_ai_call(): void
    {
        [$user, $job] = $this->studentAndJob('Blockchain Intern', ['Solidity']);
        $job->update([
            'description' => 'Build decentralized products.',
            'requirements' => 'Solidity',
        ]);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://api.groq.com/*' => Http::response($this->aiResponse(), 200),
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertStatus(503)
            ->assertJsonPath('message', 'No supported DevDocs documentation was found for this job. Please try a role with common technical skills.');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.groq.com'));
    }

    public function test_devdocs_timeout_returns_clean_error_without_ai_call(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);

        Http::fake([
            'https://devdocs.io/docs.json' => fn () => throw new ConnectionException('timeout'),
            'https://api.groq.com/*' => Http::response($this->aiResponse(), 200),
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Trusted documentation source is temporarily unavailable. Please try again.');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.groq.com'));
    }

    public function test_ai_timeout_returns_clean_error(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);

        $this->fakeDevDocs();
        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => fn () => throw new ConnectionException('timeout'),
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Interview question generation is temporarily unavailable. Please try again.');
    }

    public function test_ai_response_must_have_exactly_twenty_questions(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $this->fakeSuccessfulGeneration($this->aiResponse(20));

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $this->assertCount(20, $response->json('questions'));
    }

    public function test_duplicate_ai_questions_are_retried_and_removed_by_valid_generation(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) {
                static $attempt = 0;
                $attempt++;
                $prompt = $request->data()['messages'][0]['content'] ?? '';

                return Http::response(
                    $attempt === 1
                        ? $this->aiResponseWithDuplicateFromPrompt($prompt)
                        : $this->aiResponseFromPrompt($prompt),
                    200
                );
            },
        ]);

        $questions = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->json('questions');

        $this->assertCount(20, array_unique(array_column($questions, 'question')));
    }

    public function test_question_source_metadata_is_stored_in_attempt(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $this->fakeSuccessfulGeneration();

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $question = InterviewQuizAttempt::findOrFail($response->json('attempt_id'))->questions[0];

        $this->assertSame('devdocs', $question['source']);
        $this->assertSame('JavaScript', $question['source_doc']);
        $this->assertStringStartsWith('https://devdocs.io/', $question['source_reference']);
    }

    public function test_resume_personalization_uses_supplied_project_context(): void
    {
        [$user, $job, $student] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        Resume::create([
            'student_id' => $student->id,
            'title' => 'Student Resume',
            'projects' => [
                [
                    'name' => 'Career Platform',
                    'description' => 'Laravel and React job matching project.',
                    'technologies' => ['Laravel', 'React'],
                ],
            ],
            'experience' => [],
            'file_path' => null,
        ]);

        $this->fakeSuccessfulGeneration($this->aiResponse(20, true));

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $questions = InterviewQuizAttempt::findOrFail($response->json('attempt_id'))->questions;

        $this->assertTrue(collect($questions)->contains(
            fn (array $question) => $question['source'] === 'resume_context'
                && str_contains($question['question'], 'Career Platform')
        ));
    }

    public function test_sensitive_user_data_is_not_sent_to_ai(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $this->fakeSuccessfulGeneration();

        $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        Http::assertSent(function ($request) use ($user) {
            if (!str_contains($request->url(), 'api.groq.com')) {
                return false;
            }

            $body = json_encode($request->data(), JSON_UNESCAPED_SLASHES);

            return !str_contains($body, $user->email)
                && !str_contains($body, 'secret-password');
        });
    }

    public function test_student_must_save_job_before_generating_interview_quiz(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript'], false);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://api.groq.com/*' => Http::response($this->aiResponse(), 200),
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Please save this job before starting the interview quiz.');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.groq.com'));
    }

    public function test_first_start_creates_attempt_and_hides_correct_answers(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $this->fakeSuccessfulGeneration();

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $this->assertDatabaseCount('interview_quiz_attempts', 1);
        $this->assertNotNull($response->json('attempt_id'));
        $this->assertCount(20, $response->json('questions'));
        $this->assertArrayNotHasKey('correct_answer', $response->json('questions.0'));
        $this->assertArrayNotHasKey('source_reference', $response->json('questions.0'));
        $this->assertNotEmpty(InterviewQuizAttempt::first()->questions[0]['correct_answer']);
    }

    public function test_answer_options_are_shuffled_without_changing_the_semantic_correct_answer(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $this->fakeSuccessfulGeneration();

        $attemptId = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->json('attempt_id');

        $questions = InterviewQuizAttempt::findOrFail($attemptId)->questions;
        $answerKeys = collect($questions)->pluck('correct_answer')->all();
        $distribution = collect($answerKeys)->countBy();

        $this->assertNotSame(['A'], array_values(array_unique($answerKeys)));
        $this->assertLessThanOrEqual(8, $distribution->max());

        foreach ($questions as $question) {
            $correctText = $question['options'][$question['correct_answer']];
            $this->assertStringNotContainsString('Distractor', $correctText);
            $this->assertStringNotContainsString('Unrelated', $correctText);
            $this->assertStringNotContainsString('Incorrect', $correctText);
            $this->assertStringNotContainsString('Unsupported', $correctText);
        }
    }

    public function test_start_again_returns_same_open_attempt_without_ai_call_even_after_cache_loss(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $groqCalls = 0;

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) use (&$groqCalls) {
                $groqCalls++;

                return Http::response($this->aiResponseFromPrompt($request->data()['messages'][0]['content'] ?? ''), 200);
            },
        ]);

        $first = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        Cache::flush();

        $second = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $this->assertSame($first->json('attempt_id'), $second->json('attempt_id'));
        $this->assertSame($first->json('questions'), $second->json('questions'));
        $this->assertSame(1, $groqCalls);
    }

    public function test_submit_calculates_score_and_exposes_correct_answers_after_submit(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $this->fakeSuccessfulGeneration();

        $attemptId = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->json('attempt_id');

        $storedQuestions = collect(InterviewQuizAttempt::findOrFail($attemptId)->questions);
        $answers = $storedQuestions
            ->mapWithKeys(function (array $question, int $index) {
                $correct = $question['correct_answer'];
                $wrong = collect(['A', 'B', 'C', 'D'])->first(fn (string $key) => $key !== $correct);

                return [(string) $question['id'] => $index < 15 ? $correct : $wrong];
            })
            ->all();

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/submit', [
                'attempt_id' => $attemptId,
                'answers' => $answers,
            ])
            ->assertOk();

        $response->assertJsonPath('correct_count', 15);
        $response->assertJsonPath('score', 75);
        $response->assertJsonPath('percentage', 75);
        $this->assertSame($storedQuestions->first()['correct_answer'], $response->json('results.0.correct_answer'));
        $this->assertTrue($response->json('results.0.is_correct'));
        $this->assertFalse($response->json('results.19.is_correct'));
        $this->assertSame(InterviewQuizAttempt::STATUS_COMPLETED, InterviewQuizAttempt::find($attemptId)->status);
    }

    public function test_another_student_cannot_submit_attempt_and_completed_attempt_cannot_be_submitted_again(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $otherUser = User::create([
            'name' => 'Other Student',
            'email' => 'other@example.test',
            'password' => 'secret-password',
            'role' => 'Student',
            'status' => 'Active',
        ]);
        $otherStudent = Student::create(['user_id' => $otherUser->id, 'major' => 'Computer Science']);
        $otherStudent->savedJobs()->attach($job->id);

        $this->fakeSuccessfulGeneration();

        $attemptId = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->json('attempt_id');

        $answers = collect(range(1, 20))->mapWithKeys(fn (int $id) => [(string) $id => 'A'])->all();

        $this->actingAs($otherUser)
            ->postJson('/api/ai/interview/submit', ['attempt_id' => $attemptId, 'answers' => $answers])
            ->assertStatus(403);

        $this->actingAs($user)
            ->postJson('/api/ai/interview/submit', ['attempt_id' => $attemptId, 'answers' => $answers])
            ->assertOk();

        $this->actingAs($user)
            ->postJson('/api/ai/interview/submit', ['attempt_id' => $attemptId, 'answers' => $answers])
            ->assertStatus(422);
    }

    public function test_retake_creates_new_attempt_keeps_old_attempt_and_avoids_previous_questions(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $prompts = [];

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) use (&$prompts) {
                $prompt = $request->data()['messages'][0]['content'] ?? '';
                $prompts[] = $prompt;

                return Http::response($this->aiResponseFromPrompt($prompt), 200);
            },
        ]);

        $firstAttemptId = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->json('attempt_id');

        $answers = collect(range(1, 20))->mapWithKeys(fn (int $id) => [(string) $id => 'A'])->all();
        $this->actingAs($user)
            ->postJson('/api/ai/interview/submit', ['attempt_id' => $firstAttemptId, 'answers' => $answers])
            ->assertOk();

        $previousQuestion = InterviewQuizAttempt::find($firstAttemptId)->questions[0]['question'];

        $secondAttemptId = $this->actingAs($user)
            ->postJson('/api/ai/interview/retake', ['job_id' => $job->id])
            ->assertOk()
            ->json('attempt_id');

        $this->assertNotSame($firstAttemptId, $secondAttemptId);
        $this->assertSame(2, InterviewQuizAttempt::count());
        $this->assertSame(InterviewQuizAttempt::STATUS_COMPLETED, InterviewQuizAttempt::find($firstAttemptId)->status);
        $this->assertStringContainsString('avoid_previous_questions', $prompts[1]);
        $this->assertStringContainsString($previousQuestion, $prompts[1]);
    }

    public function test_retake_converts_open_attempt_to_abandoned_with_null_score_and_answers(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $this->fakeSuccessfulGeneration();

        $firstAttemptId = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->json('attempt_id');

        $secondAttemptId = $this->actingAs($user)
            ->postJson('/api/ai/interview/retake', ['job_id' => $job->id])
            ->assertOk()
            ->json('attempt_id');

        $abandonedAttempt = InterviewQuizAttempt::findOrFail($firstAttemptId);

        $this->assertNotSame($firstAttemptId, $secondAttemptId);
        $this->assertSame(InterviewQuizAttempt::STATUS_ABANDONED, $abandonedAttempt->status);
        $this->assertNull($abandonedAttempt->score);
        $this->assertNull($abandonedAttempt->answers);
        $this->assertNotNull($abandonedAttempt->completed_at);
    }

    public function test_submitted_attempt_becomes_completed_with_score_and_is_not_abandoned_by_retake(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $this->fakeSuccessfulGeneration();

        $firstAttemptId = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->json('attempt_id');

        $storedQuestions = collect(InterviewQuizAttempt::findOrFail($firstAttemptId)->questions);
        $answers = $storedQuestions
            ->mapWithKeys(fn (array $question) => [(string) $question['id'] => $question['correct_answer']])
            ->all();

        $this->actingAs($user)
            ->postJson('/api/ai/interview/submit', [
                'attempt_id' => $firstAttemptId,
                'answers' => $answers,
            ])
            ->assertOk()
            ->assertJsonPath('score', 100);

        $this->actingAs($user)
            ->postJson('/api/ai/interview/retake', ['job_id' => $job->id])
            ->assertOk();

        $completedAttempt = InterviewQuizAttempt::findOrFail($firstAttemptId);

        $this->assertSame(InterviewQuizAttempt::STATUS_COMPLETED, $completedAttempt->status);
        $this->assertSame(100, $completedAttempt->score);
        $this->assertNotNull($completedAttempt->answers);
    }

    public function test_history_shows_open_completed_and_abandoned_attempts_correctly(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $this->fakeSuccessfulGeneration();

        $abandonedAttemptId = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->json('attempt_id');

        $completedAttemptId = $this->actingAs($user)
            ->postJson('/api/ai/interview/retake', ['job_id' => $job->id])
            ->assertOk()
            ->json('attempt_id');

        $completedQuestions = collect(InterviewQuizAttempt::findOrFail($completedAttemptId)->questions);
        $answers = $completedQuestions
            ->mapWithKeys(function (array $question, int $index) {
                $correct = $question['correct_answer'];
                $wrong = collect(['A', 'B', 'C', 'D'])->first(fn (string $key) => $key !== $correct);

                return [(string) $question['id'] => $index < 10 ? $correct : $wrong];
            })
            ->all();

        $this->actingAs($user)
            ->postJson('/api/ai/interview/submit', [
                'attempt_id' => $completedAttemptId,
                'answers' => $answers,
            ])
            ->assertOk();

        $openAttemptId = $this->actingAs($user)
            ->postJson('/api/ai/interview/retake', ['job_id' => $job->id])
            ->assertOk()
            ->json('attempt_id');

        $response = $this->actingAs($user)
            ->getJson('/api/ai/interview/attempts?job_id=' . $job->id)
            ->assertOk();

        $attempts = collect($response->json('attempts'))->keyBy('attempt_id');

        $this->assertSame(InterviewQuizAttempt::STATUS_ABANDONED, $attempts[$abandonedAttemptId]['status']);
        $this->assertNull($attempts[$abandonedAttemptId]['score']);
        $this->assertNull($attempts[$abandonedAttemptId]['percentage']);

        $this->assertSame(InterviewQuizAttempt::STATUS_COMPLETED, $attempts[$completedAttemptId]['status']);
        $this->assertSame(50, $attempts[$completedAttemptId]['score']);
        $this->assertSame(50, $attempts[$completedAttemptId]['percentage']);

        $this->assertSame(InterviewQuizAttempt::STATUS_OPEN, $attempts[$openAttemptId]['status']);
        $this->assertNull($attempts[$openAttemptId]['score']);
        $this->assertNull($attempts[$openAttemptId]['percentage']);
    }

    public function test_attempt_history_returns_student_attempt_results_for_job(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $this->fakeSuccessfulGeneration();

        $attemptId = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->json('attempt_id');

        $storedQuestions = collect(InterviewQuizAttempt::findOrFail($attemptId)->questions);
        $answers = $storedQuestions
            ->mapWithKeys(function (array $question, int $index) {
                $correct = $question['correct_answer'];
                $wrong = collect(['A', 'B', 'C', 'D'])->first(fn (string $key) => $key !== $correct);

                return [(string) $question['id'] => $index < 10 ? $correct : $wrong];
            })
            ->all();
        $this->actingAs($user)
            ->postJson('/api/ai/interview/submit', ['attempt_id' => $attemptId, 'answers' => $answers])
            ->assertOk();

        $this->actingAs($user)
            ->getJson('/api/ai/interview/attempts?job_id=' . $job->id)
            ->assertOk()
            ->assertJsonPath('attempts.0.attempt_id', $attemptId)
            ->assertJsonPath('attempts.0.score', 50)
            ->assertJsonPath('attempts.0.status', InterviewQuizAttempt::STATUS_COMPLETED);
    }

    public function test_oversized_devdocs_context_is_reduced_before_calling_ai(): void
    {
        config([
            'services.devdocs.sections_per_doc' => 3,
            'services.devdocs.max_section_chars' => 5000,
            'services.devdocs.max_doc_context_chars' => 50000,
            'services.devdocs.max_input_tokens' => 1800,
        ]);

        [$user, $job] = $this->studentAndJob('Laravel Backend Developer', ['Laravel', 'PHP', 'MySQL', 'REST API']);
        $capturedPrompt = null;

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->largeIndexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->largeDbJson(), 200),
            'https://api.groq.com/*' => function ($request) use (&$capturedPrompt) {
                $capturedPrompt = $request->data()['messages'][0]['content'] ?? '';

                return Http::response($this->aiResponseFromPrompt($capturedPrompt), 200);
            },
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $this->assertCount(20, $response->json('questions'));
        $this->assertLessThanOrEqual(1800, $response->json('metadata.estimated_input_tokens'));
        $this->assertLessThanOrEqual(1800, (int) ceil(strlen((string) $capturedPrompt) / 4));
        $this->assertLessThan(50000, $response->json('metadata.context_character_count'));
    }

    public function test_laravel_question_cannot_be_falsely_grounded_in_php_docs(): void
    {
        [$user, $job] = $this->studentAndJob('Laravel Backend Developer', ['Laravel', 'PHP', 'REST APIs']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) {
                static $attempt = 0;
                $attempt++;
                $prompt = $request->data()['messages'][0]['content'] ?? '';

                return Http::response(
                    $attempt === 1
                        ? $this->laravelQuestionFalselyGroundedInPhp($prompt)
                        : $this->aiResponseFromPrompt($prompt),
                    200
                );
            },
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->assertJsonPath('metadata.grounding_validation_retry_occurred', true);
    }

    public function test_unbalanced_skill_coverage_is_retried(): void
    {
        [$user, $job] = $this->studentAndJob('Laravel Backend Developer', ['Laravel', 'PHP', 'REST APIs']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) {
                static $attempt = 0;
                $attempt++;
                $prompt = $request->data()['messages'][0]['content'] ?? '';

                return Http::response(
                    $attempt === 1
                        ? $this->unbalancedAiResponseFromPrompt($prompt)
                        : $this->aiResponseFromPrompt($prompt),
                    200
                );
            },
        ]);

        $questions = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->assertJsonPath('metadata.grounding_validation_retry_occurred', true)
            ->json('questions');

        $this->assertGreaterThan(1, collect($questions)->pluck('skill')->unique()->count());
    }

    public function test_ai_returns_eighteen_questions_and_corrective_request_adds_two(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) {
                static $attempt = 0;
                $attempt++;
                $prompt = $request->data()['messages'][0]['content'] ?? '';

                return Http::response($this->aiResponseFromPrompt($prompt, $attempt === 1 ? 18 : 2), 200);
            },
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $this->assertCount(20, $response->json('questions'));
        $this->assertSame(18, $response->json('metadata.generation_stats.first_call_question_count'));
        $this->assertSame(18, $response->json('metadata.generation_stats.questions_preserved'));
        $this->assertSame(2, $response->json('metadata.generation_stats.corrective_questions_requested'));
        $this->assertSame(2, $response->json('metadata.generation_stats.total_ai_calls'));
    }

    public function test_ai_returns_twenty_two_questions_and_local_selection_keeps_twenty(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $this->fakeSuccessfulGeneration($this->aiResponse(22));

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $this->assertCount(20, $response->json('questions'));
        $this->assertSame(22, $response->json('metadata.generation_stats.first_call_question_count'));
        $this->assertSame(1, $response->json('metadata.generation_stats.total_ai_calls'));
    }

    public function test_one_invalid_grounded_question_is_regenerated_only(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) {
                static $attempt = 0;
                $attempt++;
                $prompt = $request->data()['messages'][0]['content'] ?? '';

                return Http::response(
                    $attempt === 1
                        ? $this->aiResponseWithOneInvalidSource($prompt)
                        : $this->aiResponseFromPrompt($prompt, 1),
                    200
                );
            },
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $this->assertCount(20, $response->json('questions'));
        $this->assertSame(19, $response->json('metadata.generation_stats.questions_preserved'));
        $this->assertSame(1, $response->json('metadata.generation_stats.corrective_questions_requested'));
    }

    public function test_duplicate_question_is_regenerated_only(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) {
                static $attempt = 0;
                $attempt++;
                $prompt = $request->data()['messages'][0]['content'] ?? '';

                return Http::response(
                    $attempt === 1
                        ? $this->aiResponseWithDuplicateFromPrompt($prompt)
                        : $this->aiResponseFromPrompt($prompt, 1),
                    200
                );
            },
        ]);

        $questions = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->json('questions');

        $this->assertCount(20, $questions);
        $this->assertCount(20, array_unique(array_column($questions, 'question')));
    }

    public function test_json_validate_failed_on_first_call_uses_simple_json_fallback(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) {
                static $attempt = 0;
                $attempt++;

                if ($attempt === 1) {
                    return Http::response([
                        'error' => [
                            'message' => 'Failed to validate JSON. Please adjust your prompt.',
                            'code' => 'json_validate_failed',
                        ],
                    ], 400);
                }

                return Http::response($this->aiResponseFromPrompt($request->data()['messages'][0]['content'] ?? ''), 200);
            },
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $this->assertCount(20, $response->json('questions'));
        $this->assertTrue($response->json('metadata.generation_stats.json_fallback_used'));
        $this->assertSame(2, $response->json('metadata.generation_stats.total_ai_calls'));
    }

    public function test_groq_success_does_not_call_gemini_and_records_provider_metadata(): void
    {
        config(['services.gemini.keys' => ['test-gemini-key']]);
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $this->fakeSuccessfulGeneration();

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $response->assertJsonPath('metadata.ai_provider_used', 'groq');
        $response->assertJsonPath('metadata.fallback_used', false);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com'));
    }

    public function test_groq_rate_limit_falls_back_to_gemini_successfully(): void
    {
        config(['services.gemini.keys' => ['test-gemini-key']]);
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => Http::response([
                'error' => [
                    'message' => 'Rate limit reached. Please try again later.',
                    'code' => 'rate_limit_exceeded',
                ],
            ], 429),
            'https://generativelanguage.googleapis.com/*' => fn ($request) => Http::response($this->geminiResponseFromPrompt($this->geminiPrompt($request)), 200),
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $this->assertCount(20, $response->json('questions'));
        $response->assertJsonPath('metadata.ai_provider_used', 'gemini');
        $response->assertJsonPath('metadata.fallback_used', true);
        $response->assertJsonPath('metadata.generation_stats.groq_failure_category', 'rate_limit');
        $this->assertSame(2, $response->json('metadata.generation_stats.total_ai_calls'));
    }

    public function test_groq_timeout_falls_back_to_gemini_successfully(): void
    {
        config(['services.gemini.keys' => ['test-gemini-key']]);
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => fn () => throw new ConnectionException('timeout'),
            'https://generativelanguage.googleapis.com/*' => fn ($request) => Http::response($this->geminiResponseFromPrompt($this->geminiPrompt($request)), 200),
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->assertJsonPath('metadata.ai_provider_used', 'gemini')
            ->assertJsonPath('metadata.generation_stats.groq_failure_category', 'timeout');
    }

    public function test_groq_json_fallback_exhausted_falls_back_to_gemini(): void
    {
        config(['services.gemini.keys' => ['test-gemini-key']]);
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => Http::response([
                'error' => [
                    'message' => 'Failed to validate JSON. Please adjust your prompt.',
                    'code' => 'json_validate_failed',
                ],
            ], 400),
            'https://generativelanguage.googleapis.com/*' => fn ($request) => Http::response($this->geminiResponseFromPrompt($this->geminiPrompt($request)), 200),
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $this->assertCount(20, $response->json('questions'));
        $response->assertJsonPath('metadata.ai_provider_used', 'gemini');
        $response->assertJsonPath('metadata.fallback_used', true);
        $response->assertJsonPath('metadata.generation_stats.json_fallback_used', true);
        $response->assertJsonPath('metadata.generation_stats.groq_failure_category', 'json_validation');
        $this->assertSame(3, $response->json('metadata.generation_stats.total_ai_calls'));
    }

    public function test_both_ai_providers_fail_returns_clean_503(): void
    {
        config(['services.gemini.keys' => ['test-gemini-key']]);
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => Http::response([
                'error' => [
                    'message' => 'Rate limit reached.',
                    'code' => 'rate_limit_exceeded',
                ],
            ], 429),
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'Service unavailable'],
            ], 503),
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Interview question generation is temporarily unavailable. Please try again.');
    }

    public function test_gemini_fallback_output_is_grounded_shuffled_and_hides_answers(): void
    {
        config(['services.gemini.keys' => ['test-gemini-key']]);
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => Http::response([
                'error' => ['message' => 'Rate limit reached.', 'code' => 'rate_limit_exceeded'],
            ], 429),
            'https://generativelanguage.googleapis.com/*' => fn ($request) => Http::response($this->geminiResponseFromPrompt($this->geminiPrompt($request)), 200),
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $attempt = InterviewQuizAttempt::findOrFail($response->json('attempt_id'));
        $storedQuestions = collect($attempt->questions);
        $answerDistribution = $storedQuestions->pluck('correct_answer')->countBy();

        $this->assertArrayNotHasKey('correct_answer', $response->json('questions.0'));
        $this->assertSame('gemini', $response->json('metadata.ai_provider_used'));
        $this->assertTrue($storedQuestions->every(fn (array $question) => str_starts_with($question['source_reference'], 'https://devdocs.io/')));
        $this->assertLessThanOrEqual(8, $answerDistribution->max());
    }

    public function test_retake_works_using_gemini_fallback_and_creates_new_attempt(): void
    {
        config(['services.gemini.keys' => ['test-gemini-key']]);
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $groqCalls = 0;

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) use (&$groqCalls) {
                $groqCalls++;

                if ($groqCalls === 1) {
                    return Http::response($this->aiResponseFromPrompt($request->data()['messages'][0]['content'] ?? ''), 200);
                }

                return Http::response([
                    'error' => ['message' => 'Rate limit reached.', 'code' => 'rate_limit_exceeded'],
                ], 429);
            },
            'https://generativelanguage.googleapis.com/*' => fn ($request) => Http::response($this->geminiResponseFromPrompt($this->geminiPrompt($request)), 200),
        ]);

        $firstAttemptId = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk()
            ->json('attempt_id');

        $answers = collect(range(1, 20))->mapWithKeys(fn (int $id) => [(string) $id => 'A'])->all();
        $this->actingAs($user)
            ->postJson('/api/ai/interview/submit', ['attempt_id' => $firstAttemptId, 'answers' => $answers])
            ->assertOk();

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/retake', ['job_id' => $job->id])
            ->assertOk();

        $this->assertNotSame($firstAttemptId, $response->json('attempt_id'));
        $this->assertSame(2, InterviewQuizAttempt::count());
        $this->assertCount(20, $response->json('questions'));
        $response->assertJsonPath('metadata.ai_provider_used', 'gemini');
        $response->assertJsonPath('metadata.fallback_used', true);
    }

    public function test_semantic_duplicate_with_different_wording_is_rejected_and_replaced_once(): void
    {
        [$user, $job] = $this->studentAndJob('PHP Developer', ['PHP']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) {
                static $attempt = 0;
                $attempt++;
                $prompt = $request->data()['messages'][0]['content'] ?? '';

                return Http::response(
                    $attempt === 1
                        ? $this->aiResponseWithSemanticDuplicate($prompt)
                        : $this->aiResponseForOnlySkill($prompt, 'PHP', 1),
                    200
                );
            },
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $this->assertCount(20, $response->json('questions'));
        $this->assertSame(1, $response->json('metadata.generation_stats.semantic_duplicates_detected'));
        $this->assertSame(1, $response->json('metadata.generation_stats.corrective_questions_requested'));
        $this->assertSame(2, $response->json('metadata.generation_stats.total_ai_calls'));
    }

    public function test_topic_diversity_trims_one_skill_topic_dominance_and_requests_replacements(): void
    {
        [$user, $job] = $this->studentAndJob('Git Workflow Developer', ['Git']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->gitDiverseIndexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->gitDiverseDbJson(), 200),
            'https://api.groq.com/*' => function ($request) {
                static $attempt = 0;
                $attempt++;
                $prompt = $request->data()['messages'][0]['content'] ?? '';

                return Http::response(
                    $attempt === 1
                        ? $this->aiResponseWithDominantGitBranchTopic($prompt)
                        : $this->aiResponseForOnlySkill($prompt, 'Git', 19),
                    200
                );
            },
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $topics = collect(InterviewQuizAttempt::findOrFail($response->json('attempt_id'))->questions)
            ->pluck('topic')
            ->unique()
            ->values();

        $this->assertCount(20, $response->json('questions'));
        $this->assertGreaterThanOrEqual(2, $topics->count());
        $this->assertGreaterThanOrEqual(1, $response->json('metadata.generation_stats.semantic_duplicates_detected'));
        $this->assertGreaterThanOrEqual(1, $response->json('metadata.generation_stats.corrective_questions_requested'));
    }

    public function test_corrective_generation_repeats_same_fact_then_rotates_to_another_grounded_topic(): void
    {
        [$user, $job] = $this->studentAndJob('Git Workflow Developer', ['Git']);
        config(['services.devdocs.sections_per_doc' => 6]);
        $correctivePrompts = [];

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->gitDiverseIndexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->gitDiverseDbJson(), 200),
            'https://api.groq.com/*' => function ($request) use (&$correctivePrompts) {
                static $attempt = 0;
                $attempt++;
                $prompt = $request->data()['messages'][0]['content'] ?? '';

                if ($attempt === 1) {
                    return Http::response($this->gitInitialResponseWithAutoSetupMerge($prompt, 19), 200);
                }

                $correctivePrompts[] = $prompt;

                return Http::response(
                    $attempt === 2
                        ? $this->gitAutoSetupMergeDuplicateResponse($prompt)
                        : $this->gitCommitReplacementResponse($prompt),
                    200
                );
            },
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $attempt = InterviewQuizAttempt::findOrFail($response->json('attempt_id'));
        $topics = collect($attempt->questions)->pluck('topic')->unique()->values()->all();

        $this->assertCount(20, $response->json('questions'));
        $this->assertSame(1, $response->json('metadata.generation_stats.semantic_duplicates_detected'));
        $this->assertSame(3, $response->json('metadata.generation_stats.total_ai_calls'));
        $this->assertContains('commit', $topics);
        $this->assertStringContainsString('already_used_concepts', $correctivePrompts[0]);
        $this->assertStringContainsString('preferred_unused_topics', $correctivePrompts[0]);
        $this->assertNotSame(
            $response->json('metadata.generation_stats.corrective_topics_rotated_to.0.source_references.0'),
            $response->json('metadata.generation_stats.corrective_topics_rotated_to.1.source_references.0')
        );
    }

    public function test_corrective_attempt_count_is_bounded_when_semantic_duplicates_continue(): void
    {
        [$user, $job] = $this->studentAndJob('Git Workflow Developer', ['Git']);
        config(['services.devdocs.sections_per_doc' => 6]);
        $groqCalls = 0;

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->gitDiverseIndexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->gitDiverseDbJson(), 200),
            'https://api.groq.com/*' => function ($request) use (&$groqCalls) {
                $groqCalls++;
                $prompt = $request->data()['messages'][0]['content'] ?? '';

                return Http::response(
                    $groqCalls === 1
                        ? $this->gitInitialResponseWithAutoSetupMerge($prompt, 19)
                        : $this->gitAutoSetupMergeDuplicateResponse($prompt),
                    200
                );
            },
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Interview question generation is temporarily unavailable. Please try again.');

        $this->assertSame(4, $groqCalls);
    }

    public function test_groq_rate_limit_disables_groq_for_later_corrective_calls_in_same_request(): void
    {
        config(['services.gemini.keys' => ['test-gemini-key']]);
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $groqCalls = 0;
        $geminiCalls = 0;

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function () use (&$groqCalls) {
                $groqCalls++;

                return Http::response([
                    'error' => ['message' => 'Rate limit reached.', 'code' => 'rate_limit_exceeded'],
                ], 429);
            },
            'https://generativelanguage.googleapis.com/*' => function ($request) use (&$geminiCalls) {
                $geminiCalls++;
                $prompt = $this->geminiPrompt($request);

                return Http::response(
                    $geminiCalls === 1
                        ? $this->geminiResponseFromPrompt($prompt, 18)
                        : $this->geminiResponseFromPrompt($prompt, 2),
                    200
                );
            },
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $this->assertSame(1, $groqCalls);
        $this->assertSame(2, $geminiCalls);
        $this->assertSame('gemini', $response->json('metadata.ai_provider_used'));
        $this->assertTrue($response->json('metadata.generation_stats.groq_disabled_for_request'));
        $this->assertSame('groq_rate_limit', $response->json('metadata.generation_stats.provider_switch_reason'));
        $this->assertSame(['groq' => 0, 'gemini' => 1], $response->json('metadata.generation_stats.corrective_calls_by_provider'));
        $this->assertCount(20, $response->json('questions'));
    }

    public function test_new_separate_request_can_try_groq_again_after_previous_rate_limit(): void
    {
        config(['services.gemini.keys' => ['test-gemini-key']]);
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);
        $groqCalls = 0;

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) use (&$groqCalls) {
                $groqCalls++;

                if ($groqCalls === 1) {
                    return Http::response([
                        'error' => ['message' => 'Rate limit reached.', 'code' => 'rate_limit_exceeded'],
                    ], 429);
                }

                return Http::response($this->aiResponseFromPrompt($request->data()['messages'][0]['content'] ?? ''), 200);
            },
            'https://generativelanguage.googleapis.com/*' => fn ($request) => Http::response($this->geminiResponseFromPrompt($this->geminiPrompt($request)), 200),
        ]);

        $first = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();
        $this->assertTrue($first->json('metadata.generation_stats.groq_disabled_for_request'));

        $answers = collect(range(1, 20))->mapWithKeys(fn (int $id) => [(string) $id => 'A'])->all();
        $this->actingAs($user)
            ->postJson('/api/ai/interview/submit', ['attempt_id' => $first->json('attempt_id'), 'answers' => $answers])
            ->assertOk();

        $second = $this->actingAs($user)
            ->postJson('/api/ai/interview/retake', ['job_id' => $job->id])
            ->assertOk();

        $this->assertSame('groq', $second->json('metadata.ai_provider_used'));
        $this->assertSame(2, $groqCalls);
    }

    public function test_unbalanced_five_five_four_six_coverage_is_repaired_without_full_retry(): void
    {
        [$user, $job] = $this->studentAndJob('Full Stack Developer', ['JavaScript', 'PHP', 'REST APIs', 'Git']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) {
                static $attempt = 0;
                $attempt++;
                $prompt = $request->data()['messages'][0]['content'] ?? '';

                return Http::response(
                    $attempt === 1
                        ? $this->aiResponseWithSkillDistribution($prompt, ['JavaScript' => 5, 'PHP' => 5, 'REST APIs' => 4, 'Git' => 6])
                        : $this->aiResponseForOnlySkill($prompt, 'REST APIs', 1),
                    200
                );
            },
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $attempt = InterviewQuizAttempt::findOrFail($response->json('attempt_id'));
        $distribution = collect($attempt->questions)->countBy('skill')->all();

        $this->assertEquals(['JavaScript' => 5, 'PHP' => 5, 'REST APIs' => 5, 'Git' => 5], $distribution);
        $this->assertSame([['skill' => 'REST APIs', 'needed' => 1]], $response->json('metadata.generation_stats.coverage_repair_requests'));
        $this->assertSame(2, $response->json('metadata.generation_stats.total_ai_calls'));
    }

    public function test_one_incomplete_question_is_repaired_only(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) {
                static $attempt = 0;
                $attempt++;
                $prompt = $request->data()['messages'][0]['content'] ?? '';

                return Http::response(
                    $attempt === 1
                        ? $this->aiResponseWithOneIncompleteQuestion($prompt)
                        : $this->aiResponseFromPrompt($prompt, 1),
                    200
                );
            },
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertOk();

        $this->assertCount(20, $response->json('questions'));
        $this->assertSame(19, $response->json('metadata.generation_stats.initial_valid_question_count'));
        $this->assertSame(1, $response->json('metadata.generation_stats.initial_invalid_question_count'));
        $this->assertSame(2, $response->json('metadata.generation_stats.total_ai_calls'));
    }

    public function test_rate_limit_on_corrective_request_returns_clean_error(): void
    {
        [$user, $job] = $this->studentAndJob('Frontend Developer', ['JavaScript']);

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) {
                static $attempt = 0;
                $attempt++;

                if ($attempt === 1) {
                    return Http::response($this->aiResponseFromPrompt($request->data()['messages'][0]['content'] ?? '', 18), 200);
                }

                return Http::response([
                    'error' => [
                        'message' => 'Rate limit reached. Please try again in 2s.',
                        'code' => 'rate_limit_exceeded',
                    ],
                ], 429);
            },
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/interview/questions', ['job_id' => $job->id])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Interview question generation is temporarily unavailable. Please try again.');
    }

    public function test_junior_backend_retrieval_prefers_practical_diverse_sections(): void
    {
        $this->fakeRichDevDocs();
        config(['services.devdocs.sections_per_doc' => 3]);

        $result = app(DevDocsRetrievalService::class)->retrieve(
            ['Laravel', 'PHP', 'REST API'],
            'Junior Laravel Backend Developer',
            'Strong Laravel and PHP knowledge, REST API experience, Git workflow, request validation, database queries.',
            'Junior'
        );

        $references = collect($result['retrieved_documents'])->pluck('reference')->implode(' ');

        $this->assertStringContainsString('laravel~11/docs/11.x/requests', $references);
        $this->assertStringContainsString('php/pdo.prepare', $references);
        $this->assertStringContainsString('http/rfc9110#section-9', $references);
        $this->assertStringContainsString('git/git-branch', $references);
        $this->assertStringNotContainsString('language.operators.logical', $references);
        $this->assertStringNotContainsString('git-http-backend', $references);
        $this->assertStringNotContainsString('rfc9110#section-7.6', $references);
    }

    public function test_frontend_retrieval_prefers_browser_react_and_css_topics(): void
    {
        $this->fakeRichDevDocs();
        config(['services.devdocs.sections_per_doc' => 2]);

        $result = app(DevDocsRetrievalService::class)->retrieve(
            ['React', 'JavaScript', 'CSS'],
            'Frontend Developer',
            'Build interactive React interfaces with DOM events and responsive CSS layout.',
            'Junior'
        );

        $references = collect($result['retrieved_documents'])->pluck('reference')->implode(' ');

        $this->assertStringContainsString('react/hooks-state', $references);
        $this->assertStringContainsString('javascript/dom-events', $references);
        $this->assertStringContainsString('css/flexbox', $references);
    }

    public function test_database_heavy_retrieval_prefers_indexes_transactions_and_queries(): void
    {
        $this->fakeRichDevDocs();
        config(['services.devdocs.sections_per_doc' => 2]);

        $result = app(DevDocsRetrievalService::class)->retrieve(
            ['PostgreSQL', 'PHP'],
            'Database Backend Developer',
            'Optimize database queries, indexes, transactions, and reliable PHP database access.',
            'Mid'
        );

        $references = collect($result['retrieved_documents'])->pluck('reference')->implode(' ');

        $this->assertStringContainsString('postgresql~18/indexes', $references);
        $this->assertStringContainsString('postgresql~18/transactions', $references);
        $this->assertStringContainsString('php/pdo.prepare', $references);
    }

    public function test_git_mentioned_only_in_requirements_is_detected_and_uses_workflow_docs(): void
    {
        $this->fakeRichDevDocs();
        config(['services.devdocs.sections_per_doc' => 2]);

        $result = app(DevDocsRetrievalService::class)->retrieve(
            ['PHP'],
            'Backend Developer',
            'Requires clean PHP code and Git workflow for branching, commits, pull and push.',
            'Junior'
        );

        $references = collect($result['retrieved_documents'])->pluck('reference')->implode(' ');

        $this->assertContains('Git', $result['detected_from_requirements']);
        $this->assertStringContainsString('git/git-branch', $references);
        $this->assertStringContainsString('git/git-commit', $references);
        $this->assertStringNotContainsString('git-config', $references);
    }

    public function test_junior_vs_senior_level_changes_topic_ranking(): void
    {
        $this->fakeRichDevDocs();
        config(['services.devdocs.sections_per_doc' => 1]);

        $junior = app(DevDocsRetrievalService::class)->retrieve(
            ['Laravel'],
            'Junior Laravel Developer',
            'Handle requests and validation in backend features.',
            'Junior'
        );
        Cache::flush();
        $this->fakeRichDevDocs();
        $senior = app(DevDocsRetrievalService::class)->retrieve(
            ['Laravel'],
            'Senior Laravel Backend Architect',
            'Own performance, architecture, scaling and security decisions.',
            'Senior'
        );

        $this->assertStringContainsString('laravel~11/docs/11.x/requests', $junior['retrieved_documents'][0]['reference']);
        $this->assertStringContainsString('laravel~11/docs/11.x/performance', $senior['retrieved_documents'][0]['reference']);
    }

    public function test_retrieval_avoids_repeated_low_value_topics_when_better_sections_exist(): void
    {
        $this->fakeRichDevDocs();
        config(['services.devdocs.sections_per_doc' => 3]);

        $result = app(DevDocsRetrievalService::class)->retrieve(
            ['PHP'],
            'Junior PHP Backend Developer',
            'Use functions, arrays, OOP, exceptions, and PDO for backend work.',
            'Junior'
        );

        $topics = collect($result['sections'])->pluck('topic')->all();
        $references = collect($result['retrieved_documents'])->pluck('reference')->implode(' ');

        $this->assertCount(count(array_unique($topics)), $topics);
        $this->assertStringContainsString('php/pdo.prepare', $references);
        $this->assertStringContainsString('php/language.oop5.basic', $references);
        $this->assertStringNotContainsString('language.operators.logical', $references);
    }

    public function test_retrieval_ranking_is_bounded_for_large_devdocs_indexes(): void
    {
        $entries = [
            ['name' => 'PDO prepared statements', 'path' => 'pdo.prepare', 'type' => 'Reference'],
            ['name' => 'Object oriented basics', 'path' => 'language.oop5.basic', 'type' => 'Guide'],
        ];
        $database = [
            'pdo.prepare' => '<h1>PDO prepare</h1><p>Prepared statements execute parameterized database queries.</p>',
            'language.oop5.basic' => '<h1>OOP basics</h1><p>Classes and objects organize PHP application behavior.</p>',
        ];

        for ($i = 0; $i < 1000; $i++) {
            $path = 'appendix-' . $i;
            $entries[] = ['name' => 'Historical appendix ' . $i, 'path' => $path, 'type' => 'Reference'];
            $database[$path] = '<p>Unrelated appendix text.</p>';
        }

        Http::fake([
            'https://devdocs.io/docs.json' => Http::response([
                ['name' => 'PHP', 'slug' => 'php'],
            ], 200),
            'https://documents.devdocs.io/php/index.json' => Http::response(['entries' => $entries], 200),
            'https://documents.devdocs.io/php/db.json' => Http::response($database, 200),
        ]);

        config([
            'services.devdocs.max_candidate_scan' => 120,
            'services.devdocs.max_ranking_candidates' => 30,
            'services.devdocs.max_ranking_keywords' => 20,
            'services.devdocs.sections_per_doc' => 2,
        ]);

        $result = app(DevDocsRetrievalService::class)->retrieve(
            ['PHP'],
            'Junior PHP Backend Developer',
            'Use PDO and OOP basics for backend database work.',
            'Junior'
        );

        $references = collect($result['retrieved_documents'])->pluck('reference')->implode(' ');

        $this->assertLessThanOrEqual(120, $result['timing_ms']['candidate_entries_scanned']);
        $this->assertLessThanOrEqual(30, $result['timing_ms']['ranking_candidates_considered']);
        $this->assertLessThan(2000, $result['timing_ms']['ranking_ms']);
        $this->assertStringContainsString('php/pdo.prepare', $references);
        $this->assertStringContainsString('php/language.oop5.basic', $references);
    }

    private function studentAndJob(string $title, array $skillNames, bool $saved = true): array
    {
        $user = User::create([
            'name' => 'Student User',
            'email' => 'student@example.test',
            'password' => 'secret-password',
            'role' => 'Student',
            'status' => 'Active',
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'major' => 'Computer Science',
        ]);

        $companyUser = User::create([
            'name' => 'Company User',
            'email' => 'company@example.test',
            'password' => 'secret-password',
            'role' => 'Company',
            'status' => 'Active',
        ]);

        $company = Company::create([
            'user_id' => $companyUser->id,
            'company_name' => 'Example Tech',
            'approval_status' => 'Approved',
        ]);

        $job = JobPost::create([
            'company_id' => $company->id,
            'title' => $title,
            'description' => 'Build web applications using documented platform APIs and practical engineering patterns.',
            'requirements' => implode(', ', $skillNames),
            'status' => 'Open',
        ]);

        foreach ($skillNames as $skillName) {
            $skill = Skill::create(['name' => $skillName]);
            $job->skills()->attach($skill->id);
            $student->skills()->attach($skill->id);
        }

        if ($saved) {
            $student->savedJobs()->attach($job->id);
        }

        return [$user, $job, $student];
    }

    private function fakeSuccessfulGeneration(?array $aiResponse = null): void
    {
        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
            'https://api.groq.com/*' => function ($request) use ($aiResponse) {
                return Http::response($aiResponse ?? $this->aiResponseFromPrompt($request->data()['messages'][0]['content'] ?? ''), 200);
            },
        ]);
    }

    private function fakeDevDocs(): void
    {
        Http::fake([
            'https://devdocs.io/docs.json' => Http::response($this->docsJson(), 200),
            'https://documents.devdocs.io/*/index.json' => Http::response($this->indexJson(), 200),
            'https://documents.devdocs.io/*/db.json' => Http::response($this->dbJson(), 200),
        ]);
    }

    private function fakeRichDevDocs(): void
    {
        Http::fake([
            'https://devdocs.io/docs.json' => Http::response(array_merge($this->docsJson(), [
                ['name' => 'Laravel', 'slug' => 'laravel~11', 'type' => 'laravel'],
                ['name' => 'PostgreSQL', 'slug' => 'postgresql~18', 'type' => 'postgres'],
            ]), 200),
            'https://documents.devdocs.io/laravel~11/index.json' => Http::response([
                'entries' => [
                    ['name' => 'HTTP Requests', 'path' => 'docs/11.x/requests', 'type' => 'Guides: The Basics'],
                    ['name' => 'Validation', 'path' => 'docs/11.x/validation', 'type' => 'Guides: The Basics'],
                    ['name' => 'Controllers', 'path' => 'docs/11.x/controllers', 'type' => 'Guides: The Basics'],
                    ['name' => 'Database Query Builder', 'path' => 'docs/11.x/queries', 'type' => 'Guides: Database'],
                    ['name' => 'Performance Architecture', 'path' => 'docs/11.x/performance', 'type' => 'Guides: Advanced'],
                    ['name' => 'Internal Route Collection', 'path' => 'api/11.x/illuminate/routing/abstractroutecollection', 'type' => 'API'],
                ],
            ], 200),
            'https://documents.devdocs.io/laravel~11/db.json' => Http::response([
                'docs/11.x/requests' => '<h1>HTTP Requests</h1><p>Laravel requests provide input access, old input, trimming, and request data handling.</p>',
                'docs/11.x/validation' => '<h1>Validation</h1><p>Laravel validates incoming request data using validation rules.</p>',
                'docs/11.x/controllers' => '<h1>Controllers</h1><p>Controllers group request handling logic for routes.</p>',
                'docs/11.x/queries' => '<h1>Query Builder</h1><p>The query builder retrieves rows and builds database queries.</p>',
                'docs/11.x/performance' => '<h1>Performance Architecture</h1><p>Senior teams consider performance, scaling and architecture tradeoffs.</p>',
                'api/11.x/illuminate/routing/abstractroutecollection' => '<h1>Internal API</h1><p>Internal routing implementation details.</p>',
            ], 200),
            'https://documents.devdocs.io/php/index.json' => Http::response([
                'entries' => [
                    ['name' => 'PDO::prepare', 'path' => 'pdo.prepare', 'type' => 'PDO'],
                    ['name' => 'Classes and Objects', 'path' => 'language.oop5.basic', 'type' => 'Language Reference'],
                    ['name' => 'Arrays', 'path' => 'language.types.array', 'type' => 'Language Reference'],
                    ['name' => 'Exceptions', 'path' => 'language.exceptions', 'type' => 'Language Reference'],
                    ['name' => 'Logical Operators', 'path' => 'language.operators.logical', 'type' => 'Language Reference'],
                    ['name' => 'EventHttpRequest', 'path' => 'eventhttprequest.getcommand', 'type' => 'Function'],
                ],
            ], 200),
            'https://documents.devdocs.io/php/db.json' => Http::response([
                'pdo.prepare' => '<h1>PDO::prepare</h1><p>Prepares an SQL statement for execution and supports repeated database access.</p>',
                'language.oop5.basic' => '<h1>Classes and Objects</h1><p>PHP classes define objects with properties and methods.</p>',
                'language.types.array' => '<h1>Arrays</h1><p>Arrays store ordered maps and common backend data structures.</p>',
                'language.exceptions' => '<h1>Exceptions</h1><p>Exceptions handle errors in application code.</p>',
                'language.operators.logical' => '<h1>Logical Operators</h1><p>Logical operators include &&, || and !.</p>',
                'eventhttprequest.getcommand' => '<h1>EventHttpRequest</h1><p>Specialized event HTTP request internals.</p>',
            ], 200),
            'https://documents.devdocs.io/http/index.json' => Http::response([
                'entries' => [
                    ['name' => 'HTTP Methods', 'path' => 'rfc9110#section-9', 'type' => 'HTTP Semantics'],
                    ['name' => 'Status Codes', 'path' => 'rfc9110#section-15', 'type' => 'HTTP Semantics'],
                    ['name' => 'Headers', 'path' => 'rfc9110#section-6', 'type' => 'HTTP Semantics'],
                    ['name' => 'Gateway Internals', 'path' => 'rfc9110#section-7.6', 'type' => 'HTTP Semantics'],
                    ['name' => 'Proxy Caching', 'path' => 'rfc9111#section-4', 'type' => 'HTTP Caching'],
                ],
            ], 200),
            'https://documents.devdocs.io/http/db.json' => Http::response([
                'rfc9110' => '<h1>HTTP Semantics</h1><p>HTTP methods define request semantics. Status codes describe response results. Headers carry request and response metadata.</p>',
                'rfc9111' => '<h1>HTTP Caching</h1><p>Proxy caching and intermediaries are advanced HTTP architecture topics.</p>',
            ], 200),
            'https://documents.devdocs.io/git/index.json' => Http::response([
                'entries' => [
                    ['name' => 'git branch', 'path' => 'git-branch', 'type' => 'Branching and Merging'],
                    ['name' => 'git commit', 'path' => 'git-commit', 'type' => 'Basic Snapshotting'],
                    ['name' => 'git pull', 'path' => 'git-pull', 'type' => 'Sharing and Updating Projects'],
                    ['name' => 'git push', 'path' => 'git-push', 'type' => 'Sharing and Updating Projects'],
                    ['name' => 'git config', 'path' => 'git-config', 'type' => 'Configuration'],
                    ['name' => 'git http backend', 'path' => 'git-http-backend', 'type' => 'Git Internals'],
                ],
            ], 200),
            'https://documents.devdocs.io/git/db.json' => Http::response([
                'git-branch' => '<h1>git branch</h1><p>List, create, or delete branches for collaborative workflow.</p>',
                'git-commit' => '<h1>git commit</h1><p>Record staged changes in project history.</p>',
                'git-pull' => '<h1>git pull</h1><p>Fetch and integrate changes from a remote repository.</p>',
                'git-push' => '<h1>git push</h1><p>Update remote refs with local commits.</p>',
                'git-config' => '<h1>git config</h1><p>Advanced configuration details.</p>',
                'git-http-backend' => '<h1>git http backend</h1><p>Server internals for Git over HTTP.</p>',
            ], 200),
            'https://documents.devdocs.io/react/index.json' => Http::response([
                'entries' => [
                    ['name' => 'Hooks State', 'path' => 'hooks-state', 'type' => 'Guide'],
                    ['name' => 'Components and Props', 'path' => 'components-props', 'type' => 'Guide'],
                ],
            ], 200),
            'https://documents.devdocs.io/react/db.json' => Http::response([
                'hooks-state' => '<h1>Hooks State</h1><p>React hooks manage component state.</p>',
                'components-props' => '<h1>Components</h1><p>Components receive props and render UI.</p>',
            ], 200),
            'https://documents.devdocs.io/javascript/index.json' => Http::response([
                'entries' => [
                    ['name' => 'DOM Events', 'path' => 'dom-events', 'type' => 'Guide'],
                    ['name' => 'Arrays', 'path' => 'arrays', 'type' => 'Reference'],
                ],
            ], 200),
            'https://documents.devdocs.io/javascript/db.json' => Http::response([
                'dom-events' => '<h1>DOM Events</h1><p>Browser events respond to user interactions.</p>',
                'arrays' => '<h1>Arrays</h1><p>Arrays store ordered values.</p>',
            ], 200),
            'https://documents.devdocs.io/css/index.json' => Http::response([
                'entries' => [
                    ['name' => 'Flexbox', 'path' => 'flexbox', 'type' => 'Layout'],
                    ['name' => 'Grid Layout', 'path' => 'grid', 'type' => 'Layout'],
                ],
            ], 200),
            'https://documents.devdocs.io/css/db.json' => Http::response([
                'flexbox' => '<h1>Flexbox</h1><p>Flexbox lays out responsive components.</p>',
                'grid' => '<h1>Grid</h1><p>CSS Grid creates two-dimensional layouts.</p>',
            ], 200),
            'https://documents.devdocs.io/postgresql~18/index.json' => Http::response([
                'entries' => [
                    ['name' => 'Indexes', 'path' => 'indexes', 'type' => 'Performance'],
                    ['name' => 'Transactions', 'path' => 'transactions', 'type' => 'Reliability'],
                ],
            ], 200),
            'https://documents.devdocs.io/postgresql~18/db.json' => Http::response([
                'indexes' => '<h1>Indexes</h1><p>Indexes improve query lookup performance.</p>',
                'transactions' => '<h1>Transactions</h1><p>Transactions group database changes reliably.</p>',
            ], 200),
        ]);
    }

    private function docsJson(): array
    {
        return [
            ['name' => 'JavaScript', 'slug' => 'javascript', 'type' => 'mdn'],
            ['name' => 'TypeScript', 'slug' => 'typescript', 'type' => 'typescript'],
            ['name' => 'HTML', 'slug' => 'html', 'type' => 'mdn'],
            ['name' => 'CSS', 'slug' => 'css', 'type' => 'mdn'],
            ['name' => 'React', 'slug' => 'react', 'type' => 'react'],
            ['name' => 'PHP', 'slug' => 'php', 'type' => 'php'],
            ['name' => 'MySQL', 'slug' => 'mysql', 'type' => 'mysql'],
            ['name' => 'Docker', 'slug' => 'docker', 'type' => 'docker'],
            ['name' => 'Git', 'slug' => 'git', 'type' => 'git'],
            ['name' => 'HTTP', 'slug' => 'http', 'type' => 'mdn'],
        ];
    }

    private function indexJson(): array
    {
        return [
            'entries' => [
                ['name' => 'Functions', 'path' => 'functions', 'type' => 'Guide'],
                ['name' => 'HTTP request methods', 'path' => 'methods', 'type' => 'Reference'],
                ['name' => 'Database indexes', 'path' => 'indexes', 'type' => 'Guide'],
                ['name' => 'Components', 'path' => 'components', 'type' => 'Reference'],
            ],
        ];
    }

    private function dbJson(): array
    {
        return [
            'functions' => '<h1>Functions</h1><p>Functions are reusable blocks that accept input and return output.</p>',
            'methods' => '<h1>HTTP methods</h1><p>HTTP request methods describe the action to perform for a resource.</p>',
            'indexes' => '<h1>Indexes</h1><p>Database indexes help queries find rows efficiently.</p>',
            'components' => '<h1>Components</h1><p>Components describe reusable UI pieces with state and props.</p>',
        ];
    }

    private function largeIndexJson(): array
    {
        return [
            'entries' => [
                ['name' => 'Laravel controllers and HTTP request handling', 'path' => 'controllers', 'type' => 'Guide'],
                ['name' => 'PHP functions for backend applications', 'path' => 'php-functions', 'type' => 'Reference'],
                ['name' => 'MySQL indexes for REST API queries', 'path' => 'mysql-indexes', 'type' => 'Guide'],
                ['name' => 'Unrelated historical appendix', 'path' => 'appendix', 'type' => 'Guide'],
            ],
        ];
    }

    private function largeDbJson(): array
    {
        $important = str_repeat(
            'Laravel backend developers use controllers to receive HTTP requests. REST APIs return structured responses. MySQL indexes help backend queries find rows efficiently. PHP code should validate request data before database writes. ',
            80
        );
        $duplicate = str_repeat(
            'Duplicate DevDocs paragraph about PHP request handling and REST API responses. ',
            120
        );

        return [
            'controllers' => '<h1>Controllers</h1><p>' . $important . '</p>',
            'php-functions' => '<h1>Functions</h1><p>' . $duplicate . '</p>',
            'mysql-indexes' => '<h1>Indexes</h1><p>' . $important . '</p>',
            'appendix' => '<h1>Appendix</h1><p>' . str_repeat('Unrelated reference text. ', 200) . '</p>',
        ];
    }

    private function aiResponse(int $count = 20, bool $includeResumeQuestion = false): array
    {
        $questions = [];

        for ($i = 1; $i <= $count; $i++) {
            $questions[] = [
                'id' => $i,
                'question' => $includeResumeQuestion && $i === 20
                    ? 'How would you explain the documented JavaScript decisions in your Career Platform project?'
                    : "DevDocs grounded interview question {$i}?",
                'options' => [
                    'A' => "Correct option {$i}",
                    'B' => "Distractor B {$i}",
                    'C' => "Distractor C {$i}",
                    'D' => "Distractor D {$i}",
                ],
                'correct_answer' => 'A',
                'difficulty' => $i <= 5 ? 'easy' : ($i <= 15 ? 'medium' : 'hard'),
                'skill' => 'JavaScript',
                'source' => $includeResumeQuestion && $i === 20 ? 'resume_context' : 'devdocs',
                'source_doc' => 'JavaScript',
                'source_reference' => 'https://devdocs.io/javascript/functions',
            ];
        }

        return ['choices' => [['message' => ['content' => json_encode(['questions' => $questions])]]]];
    }

    private function aiResponseFromPrompt(string $prompt, int $count = 20): array
    {
        $docs = $this->trustedDocsFromPrompt($prompt);
        $offset = $this->avoidMaxNumberFromPrompt($prompt);

        if (empty($docs)) {
            return $this->aiResponse($count);
        }

        $questions = [];
        for ($i = 1; $i <= $count; $i++) {
            $doc = $docs[($i - 1) % count($docs)];
            $number = $offset + $i;
            $questions[] = [
                'id' => $i,
                'question' => "What practical concept from {$doc['skill']} documentation should a candidate know for item {$number}?",
                'options' => [
                    'A' => "Supported {$doc['skill']} concept {$number}",
                    'B' => "Unrelated distractor {$number}",
                    'C' => "Incorrect shortcut {$number}",
                    'D' => "Unsupported claim {$number}",
                ],
                'correct_answer' => 'A',
                'difficulty' => $i <= 7 ? 'easy' : ($i <= 16 ? 'medium' : 'hard'),
                'skill' => $doc['skill'],
                'source' => 'devdocs',
                'source_doc' => $doc['doc_name'],
                'source_reference' => $doc['source_reference'],
            ];
        }

        return ['choices' => [['message' => ['content' => json_encode(['questions' => $questions])]]]];
    }

    private function trustedDocsFromPrompt(string $prompt): array
    {
        $inputPosition = strpos($prompt, 'Input:');
        if ($inputPosition === false) {
            return [];
        }

        $json = trim(substr($prompt, $inputPosition + strlen('Input:')));
        $correctivePosition = strpos($json, "\n\nCorrective generation only.");
        if ($correctivePosition !== false) {
            $json = trim(substr($json, 0, $correctivePosition));
        }

        $payload = json_decode($json, true);

        return $payload['trusted_documentation'] ?? $payload['docs'] ?? [];
    }

    private function geminiPrompt($request): string
    {
        return $request->data()['contents'][0]['parts'][0]['text'] ?? '';
    }

    private function geminiResponseFromPrompt(string $prompt, int $count = 20): array
    {
        $groqResponse = $this->aiResponseFromPrompt($prompt, $count);

        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => $groqResponse['choices'][0]['message']['content'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function avoidMaxNumberFromPrompt(string $prompt): int
    {
        if (!preg_match('/Avoid duplicates of these questions:\s*(\[.*?\])\s*(?:\n\nCorrective diversity requirements:|$)/s', $prompt, $matches)) {
            return 0;
        }

        $questions = json_decode($matches[1], true);

        if (!is_array($questions)) {
            return 0;
        }

        return collect($questions)
            ->map(function (string $question) {
                return preg_match('/item\s+(\d+)/i', $question, $matches) ? (int) $matches[1] : 0;
            })
            ->max() ?? count($questions);
    }

    private function aiResponseWithDuplicate(): array
    {
        $response = $this->aiResponse();
        $payload = json_decode($response['choices'][0]['message']['content'], true);
        $payload['questions'][1]['question'] = $payload['questions'][0]['question'];
        $response['choices'][0]['message']['content'] = json_encode($payload);

        return $response;
    }

    private function aiResponseWithDuplicateFromPrompt(string $prompt): array
    {
        $response = $this->aiResponseFromPrompt($prompt);
        $payload = json_decode($response['choices'][0]['message']['content'], true);
        $payload['questions'][1]['question'] = $payload['questions'][0]['question'];
        $response['choices'][0]['message']['content'] = json_encode($payload);

        return $response;
    }

    private function aiResponseWithOneInvalidSource(string $prompt): array
    {
        $response = $this->aiResponseFromPrompt($prompt);
        $payload = json_decode($response['choices'][0]['message']['content'], true);
        $payload['questions'][0]['source_reference'] = 'https://devdocs.io/not/retrieved';
        $response['choices'][0]['message']['content'] = json_encode($payload);

        return $response;
    }

    private function aiResponseWithOneIncompleteQuestion(string $prompt): array
    {
        $response = $this->aiResponseFromPrompt($prompt);
        $payload = json_decode($response['choices'][0]['message']['content'], true);
        unset($payload['questions'][0]['options']['D']);
        $response['choices'][0]['message']['content'] = json_encode($payload);

        return $response;
    }

    private function aiResponseWithSemanticDuplicate(string $prompt): array
    {
        $response = $this->aiResponseFromPrompt($prompt);
        $payload = json_decode($response['choices'][0]['message']['content'], true);

        $payload['questions'][0]['question'] = 'Before PHP 8.0, what occurs when $this is used where it is undefined?';
        $payload['questions'][0]['options']['A'] = 'It is treated as undefined';
        $payload['questions'][0]['skill'] = 'PHP';
        $payload['questions'][1]['question'] = 'Before PHP 8.0, what happens if code references $this when it is not defined?';
        $payload['questions'][1]['options']['A'] = 'It behaves as undefined';
        $payload['questions'][1]['skill'] = 'PHP';

        $response['choices'][0]['message']['content'] = json_encode($payload);

        return $response;
    }

    private function aiResponseWithDominantGitBranchTopic(string $prompt): array
    {
        $docs = collect($this->trustedDocsFromPrompt($prompt));
        $branchDoc = $docs->first(fn (array $doc) => str_contains((string) $doc['source_reference'], 'git-branch')) ?? $docs->first();
        $questions = [];

        for ($i = 1; $i <= 20; $i++) {
            $questions[] = $this->questionForDoc($branchDoc, $i, "branch workflow topic {$i}");
            $questions[$i - 1]['question'] = "Which Git branch workflow setting should a candidate understand for branch scenario {$i}?";
        }

        return ['choices' => [['message' => ['content' => json_encode(['questions' => $questions])]]]];
    }

    private function gitDiverseIndexJson(): array
    {
        return [
            'entries' => [
                ['name' => 'git branch', 'path' => 'git-branch', 'type' => 'Branching and Merging'],
                ['name' => 'git commit', 'path' => 'git-commit', 'type' => 'Basic Snapshotting'],
                ['name' => 'git merge', 'path' => 'git-merge', 'type' => 'Branching and Merging'],
                ['name' => 'git checkout', 'path' => 'git-checkout', 'type' => 'Branching and Merging'],
                ['name' => 'git pull', 'path' => 'git-pull', 'type' => 'Sharing and Updating Projects'],
                ['name' => 'git push', 'path' => 'git-push', 'type' => 'Sharing and Updating Projects'],
            ],
        ];
    }

    private function gitDiverseDbJson(): array
    {
        return [
            'git-branch' => '<h1>git branch</h1><p>Branches support isolated workflow and upstream branch setup.</p>',
            'git-commit' => '<h1>git commit</h1><p>Commits record staged snapshots in project history.</p>',
            'git-merge' => '<h1>git merge</h1><p>Merging combines histories and may require resolving conflicts.</p>',
            'git-checkout' => '<h1>git checkout</h1><p>Checkout switches branches or restores working tree paths.</p>',
            'git-pull' => '<h1>git pull</h1><p>Pull fetches and integrates changes from a remote repository.</p>',
            'git-push' => '<h1>git push</h1><p>Push updates remote refs with local commits.</p>',
        ];
    }

    private function gitInitialResponseWithAutoSetupMerge(string $prompt, int $count): array
    {
        $response = $this->aiResponseForOnlySkill($prompt, 'Git', $count);
        $payload = json_decode($response['choices'][0]['message']['content'], true);
        $branchDoc = collect($this->trustedDocsFromPrompt($prompt))
            ->first(fn (array $doc) => str_contains((string) ($doc['source_reference'] ?? ''), 'git-branch'));

        if ($branchDoc) {
            $payload['questions'][0] = $this->questionForDoc($branchDoc, 1, 'branch autosetupmerge seed');
            $payload['questions'][0]['question'] = 'What Git branch.autoSetupMerge behavior should a candidate understand?';
            $payload['questions'][0]['options']['A'] = 'It controls upstream branch setup behavior';
        }

        $response['choices'][0]['message']['content'] = json_encode($payload);

        return $response;
    }

    private function gitAutoSetupMergeDuplicateResponse(string $prompt): array
    {
        $branchDoc = collect($this->trustedDocsFromPrompt($prompt))
            ->first(fn (array $doc) => str_contains((string) ($doc['source_reference'] ?? ''), 'git-branch'))
            ?? collect($this->trustedDocsFromPrompt($prompt))->first();
        $question = $this->questionForDoc($branchDoc, 1, 'branch autosetupmerge duplicate');
        $question['question'] = 'How does Git branch.autoSetupMerge affect upstream branch setup?';
        $question['options']['A'] = 'It configures upstream branch setup behavior';

        return ['choices' => [['message' => ['content' => json_encode(['questions' => [$question]])]]]];
    }

    private function gitCommitReplacementResponse(string $prompt): array
    {
        $commitDoc = collect($this->trustedDocsFromPrompt($prompt))
            ->first(fn (array $doc) => str_contains((string) ($doc['source_reference'] ?? ''), 'git-commit'))
            ?? collect($this->trustedDocsFromPrompt($prompt))->first();
        $question = $this->questionForDoc($commitDoc, 1, 'commit snapshot replacement');
        $question['question'] = 'What does Git commit record from the staged snapshot?';
        $question['options']['A'] = 'A project history snapshot';

        return ['choices' => [['message' => ['content' => json_encode(['questions' => [$question]])]]]];
    }

    private function laravelQuestionFalselyGroundedInPhp(string $prompt): array
    {
        $response = $this->aiResponseFromPrompt($prompt);
        $payload = json_decode($response['choices'][0]['message']['content'], true);
        $phpDoc = collect($this->trustedDocsFromPrompt($prompt))->firstWhere('skill', 'PHP')
            ?? collect($this->trustedDocsFromPrompt($prompt))->first();

        $payload['questions'][0]['question'] = 'Which Laravel feature validates incoming requests?';
        $payload['questions'][0]['skill'] = 'PHP';
        $payload['questions'][0]['source_doc'] = $phpDoc['doc_name'];
        $payload['questions'][0]['source_reference'] = $phpDoc['source_reference'];

        $response['choices'][0]['message']['content'] = json_encode($payload);

        return $response;
    }

    private function unbalancedAiResponseFromPrompt(string $prompt): array
    {
        $docs = $this->trustedDocsFromPrompt($prompt);
        $doc = $docs[0] ?? ['skill' => 'PHP', 'doc_name' => 'PHP', 'source_reference' => 'https://devdocs.io/php/functions'];
        $questions = [];

        for ($i = 1; $i <= 20; $i++) {
            $questions[] = [
                'id' => $i,
                'question' => "What practical concept from {$doc['skill']} documentation should a candidate know for item {$i}?",
                'options' => [
                    'A' => "Supported {$doc['skill']} concept {$i}",
                    'B' => "Unrelated distractor {$i}",
                    'C' => "Incorrect shortcut {$i}",
                    'D' => "Unsupported claim {$i}",
                ],
                'correct_answer' => 'A',
                'difficulty' => 'medium',
                'skill' => $doc['skill'],
                'source' => 'devdocs',
                'source_doc' => $doc['doc_name'],
                'source_reference' => $doc['source_reference'],
            ];
        }

        return ['choices' => [['message' => ['content' => json_encode(['questions' => $questions])]]]];
    }

    private function aiResponseWithSkillDistribution(string $prompt, array $distribution): array
    {
        $docs = collect($this->trustedDocsFromPrompt($prompt));
        $questions = [];
        $id = 1;

        foreach ($distribution as $skill => $count) {
            $doc = $docs->first(fn (array $doc) => $this->sameTestSkill((string) $doc['skill'], (string) $skill))
                ?? $docs->first();

            for ($i = 1; $i <= $count; $i++) {
                $questions[] = $this->questionForDoc($doc, $id, "{$skill} coverage item {$i}");
                $id++;
            }
        }

        return ['choices' => [['message' => ['content' => json_encode(['questions' => $questions])]]]];
    }

    private function aiResponseForOnlySkill(string $prompt, string $skill, int $count): array
    {
        $docs = collect($this->trustedDocsFromPrompt($prompt));
        $matchingDocs = $docs
            ->filter(fn (array $doc) => $this->sameTestSkill((string) $doc['skill'], $skill))
            ->values();
        if ($matchingDocs->isEmpty() && $this->sameTestSkill('http', $skill)) {
            $matchingDocs = $docs
                ->filter(fn (array $doc) => str_contains((string) ($doc['source_reference'] ?? ''), '/http/'))
                ->values();
        }
        if ($matchingDocs->isEmpty() && $this->sameTestSkill('http', $skill)) {
            $matchingDocs = collect([[
                'skill' => 'HTTP',
                'doc_name' => 'HTTP',
                'source_reference' => 'https://devdocs.io/http/methods',
            ]]);
        }
        if ($matchingDocs->isEmpty() && $docs->isNotEmpty()) {
            $matchingDocs = collect([$docs->first()]);
        }
        $questions = [];
        $offset = $this->avoidMaxNumberFromPrompt($prompt);

        for ($i = 1; $i <= $count; $i++) {
            $number = $offset + $i;
            $doc = $matchingDocs[($i - 1) % max(1, $matchingDocs->count())];
            $question = $this->questionForDoc($doc, $i, "{$skill} repair item {$number}");
            $question['skill'] = $skill;
            $questions[] = $question;
        }

        return ['choices' => [['message' => ['content' => json_encode(['questions' => $questions])]]]];
    }

    private function questionForDoc(array $doc, int $id, string $label): array
    {
        return [
            'id' => $id,
            'question' => "What practical concept from {$doc['skill']} documentation should a candidate know for {$label}?",
            'options' => [
                'A' => "Supported {$doc['skill']} concept {$label}",
                'B' => "Unrelated distractor {$label}",
                'C' => "Incorrect shortcut {$label}",
                'D' => "Unsupported claim {$label}",
            ],
            'correct_answer' => 'A',
            'difficulty' => 'medium',
            'skill' => $doc['skill'],
            'source' => 'devdocs',
            'source_doc' => $doc['doc_name'],
            'source_reference' => $doc['source_reference'],
        ];
    }

    private function sameTestSkill(string $left, string $right): bool
    {
        $normalize = function (string $skill): string {
            $skill = strtolower($skill);

            return str_contains($skill, 'rest') || str_contains($skill, 'api') || str_contains($skill, 'http')
                ? 'rest apis'
                : trim($skill);
        };

        return $normalize($left) === $normalize($right);
    }
}
