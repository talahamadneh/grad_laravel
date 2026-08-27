<?php

namespace Tests\Feature;

use App\Models\Resume;
use App\Models\Student;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ResumeUploadAIParsingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['resumes', 'students', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('phone')->nullable();
            $table->string('location')->nullable();
            $table->string('linkedin')->nullable();
            $table->string('github')->nullable();
            $table->string('portfolio')->nullable();
            $table->string('headline')->nullable();
            $table->text('bio')->nullable();
            $table->timestamps();
        });

        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->string('title')->nullable();
            $table->string('template')->nullable();
            $table->string('full_name')->nullable();
            $table->string('professional_title')->nullable();
            $table->text('summary')->nullable();
            $table->json('education')->nullable();
            $table->json('skills')->nullable();
            $table->json('experience')->nullable();
            $table->decimal('total_years_experience', 4, 1)->nullable();
            $table->json('projects')->nullable();
            $table->json('certificates')->nullable();
            $table->json('languages')->nullable();
            $table->boolean('is_public')->default(false);
            $table->string('file_path')->nullable();
            $table->timestamps();
        });

        config([
            'services.groq.keys' => ['groq-key'],
            'services.gemini.keys' => ['gemini-key'],
            'services.groq.model' => 'groq-test-model',
            'services.gemini.model' => 'gemini-test-model',
        ]);
    }

    public function test_groq_succeeds_with_valid_structured_resume_json(): void
    {
        Storage::fake('public');
        Log::spy();

        $student = $this->student();
        Sanctum::actingAs($student->user);

        Http::fake([
            'https://api.groq.com/*' => Http::response($this->groqResponse($this->parsedResume()), 200),
            'https://generativelanguage.googleapis.com/*' => Http::response(['unexpected' => true], 500),
        ]);

        $response = $this->postJson('/api/student/resume/upload', [
            'file' => $this->pdfUpload($this->resumeLines()),
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'CV uploaded and analyzed successfully')
            ->assertJsonStructure(['message', 'resume', 'file_path', 'file_name', 'file_url']);

        $resume = Resume::first();
        $this->assertSame('Sarah Ahmad', $resume->full_name);
        $this->assertSame('Junior Backend Developer', $resume->professional_title);
        $this->assertSame('Backend developer focused on Laravel APIs.', $resume->summary);
        $this->assertSame(['Laravel', 'PHP', 'MySQL'], $resume->skills);
        $this->assertSame('Career Platform', $resume->projects[0]['name']);

        $student->refresh();
        $this->assertSame('+962 79 123 4567', $student->phone);
        $this->assertSame('Amman, Jordan', $student->location);
        $this->assertSame('https://linkedin.com/in/sarahahmad', $student->linkedin);
        $this->assertSame('https://github.com/sarahahmad', $student->github);
        $this->assertSame('https://sarah.dev', $student->portfolio);
        $this->assertSame('Junior Backend Developer', $student->headline);
        $this->assertSame('Backend developer focused on Laravel APIs.', $student->bio);

        Storage::disk('public')->assertExists($response->json('file_path'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.groq.com'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com'));
        Log::shouldHaveReceived('info')
            ->with('Resume upload parsing succeeded', Mockery::on(
                fn (array $context) => $this->safeLogContext($context, 'groq')
            ));
    }

    public function test_groq_rate_limited_falls_back_to_gemini_successfully(): void
    {
        Storage::fake('public');

        $student = $this->student();
        Sanctum::actingAs($student->user);

        Http::fake([
            'https://api.groq.com/*' => Http::response(['error' => ['message' => 'rate limit']], 429),
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse($this->parsedResume()), 200),
        ]);

        $this->postJson('/api/student/resume/upload', [
            'file' => $this->pdfUpload($this->resumeLines()),
        ])->assertOk();

        $this->assertDatabaseHas('resumes', ['full_name' => 'Sarah Ahmad']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.groq.com'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com'));
    }

    public function test_groq_network_failure_falls_back_to_gemini_successfully(): void
    {
        Storage::fake('public');

        $student = $this->student();
        Sanctum::actingAs($student->user);

        Http::fake([
            'https://api.groq.com/*' => fn () => throw new ConnectionException('timeout'),
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse($this->parsedResume()), 200),
        ]);

        $this->postJson('/api/student/resume/upload', [
            'file' => $this->pdfUpload($this->resumeLines()),
        ])->assertOk();

        $this->assertDatabaseHas('resumes', ['full_name' => 'Sarah Ahmad']);
    }

    public function test_malformed_ai_json_is_rejected_and_not_stored(): void
    {
        Storage::fake('public');

        $student = $this->student();
        Sanctum::actingAs($student->user);

        Http::fake([
            'https://api.groq.com/*' => Http::response($this->groqContent('not valid json'), 200),
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse($this->parsedResume()), 200),
        ]);

        $response = $this->postJson('/api/student/resume/upload', [
            'file' => $this->pdfUpload($this->resumeLines()),
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Could not understand the uploaded CV.']);

        $this->assertDatabaseCount('resumes', 0);
        Storage::disk('public')->assertMissing("resumes/{$student->id}/resume.pdf");
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com'));
    }

    public function test_both_providers_fail_returns_clean_503(): void
    {
        Storage::fake('public');

        $student = $this->student();
        Sanctum::actingAs($student->user);

        Http::fake([
            'https://api.groq.com/*' => Http::response(['error' => ['message' => 'unavailable']], 503),
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'unavailable']], 503),
        ]);

        $response = $this->postJson('/api/student/resume/upload', [
            'file' => $this->pdfUpload($this->resumeLines()),
        ]);

        $response->assertStatus(503)
            ->assertExactJson(['message' => 'Resume parsing service unavailable']);

        $this->assertDatabaseCount('resumes', 0);
        Storage::disk('public')->assertMissing("resumes/{$student->id}/resume.pdf");
    }

    public function test_resume_text_and_personal_data_are_not_logged(): void
    {
        Storage::fake('public');
        Log::spy();

        $student = $this->student();
        Sanctum::actingAs($student->user);

        Http::fake([
            'https://api.groq.com/*' => Http::response($this->groqResponse($this->parsedResume()), 200),
        ]);

        $this->postJson('/api/student/resume/upload', [
            'file' => $this->pdfUpload($this->resumeLines()),
        ])->assertOk();

        foreach (['Sarah Ahmad', 'sarah@example.com', '+962 79 123 4567', 'linkedin.com/in/sarahahmad'] as $privateValue) {
            Log::shouldNotHaveReceived('info', [Mockery::any(), Mockery::on(
                fn ($context) => is_array($context) && str_contains(json_encode($context), $privateValue)
            )]);
            Log::shouldNotHaveReceived('warning', [Mockery::any(), Mockery::on(
                fn ($context) => is_array($context) && str_contains(json_encode($context), $privateValue)
            )]);
            Log::shouldNotHaveReceived('error', [Mockery::any(), Mockery::on(
                fn ($context) => is_array($context) && str_contains(json_encode($context), $privateValue)
            )]);
        }
    }

    private function safeLogContext(array $context, string $provider): bool
    {
        return ($context['provider'] ?? null) === $provider
            && isset($context['characters'], $context['duration_ms'])
            && !isset($context['email'], $context['phone'], $context['text'], $context['response']);
    }

    private function student(): Student
    {
        $user = User::create([
            'name' => 'Sarah Ahmad',
            'email' => 'sarah@example.com',
            'role' => 'Student',
        ]);

        return Student::create(['user_id' => $user->id]);
    }

    private function parsedResume(): array
    {
        return [
            'full_name' => 'Sarah Ahmad',
            'professional_title' => 'Junior Backend Developer',
            'summary' => 'Backend developer focused on Laravel APIs.',
            'email' => 'sarah@example.com',
            'phone' => '+962 79 123 4567',
            'location' => 'Amman, Jordan',
            'linkedin' => 'https://linkedin.com/in/sarahahmad',
            'github' => 'https://github.com/sarahahmad',
            'portfolio' => 'https://sarah.dev',
            'education' => [[
                'degree' => 'Bachelor of Computer Science',
                'university' => 'Jordan University',
                'field_of_study' => 'Computer Science',
                'start_date' => '2019',
                'end_date' => '2023',
            ]],
            'skills' => ['Laravel', 'PHP', 'MySQL'],
            'experience' => [],
            'projects' => [[
                'name' => 'Career Platform',
                'link' => null,
                'description' => 'Built a Laravel and MySQL platform.',
            ]],
            'certificates' => [],
            'languages' => [[
                'language' => 'English',
                'level' => 'Professional',
            ]],
        ];
    }

    private function groqResponse(array $data): array
    {
        return $this->groqContent(json_encode($data));
    }

    private function groqContent(string $content): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => $content,
                    ],
                ],
            ],
        ];
    }

    private function geminiResponse(array $data): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => json_encode($data)],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function resumeLines(): array
    {
        return [
            'Sarah Ahmad',
            'Junior Backend Developer',
            'sarah@example.com',
            '+962 79 123 4567',
            'https://linkedin.com/in/sarahahmad',
            'Skills',
            'Laravel, PHP, MySQL',
        ];
    }

    private function pdfUpload(array $lines): UploadedFile
    {
        $html = collect($lines)
            ->map(fn (string $line) => '<p>' . e($line) . '</p>')
            ->implode('');

        $path = tempnam(sys_get_temp_dir(), 'resume-test-') . '.pdf';
        file_put_contents($path, Pdf::loadHTML($html)->output());

        return new UploadedFile($path, 'resume.pdf', 'application/pdf', null, true);
    }
}
