<?php

namespace Tests\Feature;

use App\Models\Resume;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CVReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['resume_analysis', 'resumes', 'students', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('Student');
            $table->string('status')->default('Active');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('university')->nullable();
            $table->string('major')->nullable();
            $table->string('phone')->nullable();
            $table->string('linkedin')->nullable();
            $table->string('github')->nullable();
            $table->string('portfolio')->nullable();
            $table->text('bio')->nullable();
            $table->string('headline')->nullable();
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
            $table->json('experience')->nullable();
            $table->json('education')->nullable();
            $table->json('skills')->nullable();
            $table->json('projects')->nullable();
            $table->json('certificates')->nullable();
            $table->json('languages')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });

        Schema::create('resume_analysis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resume_id');
            $table->integer('cv_score')->nullable();
            $table->integer('ats_score')->nullable();
            $table->text('strengths')->nullable();
            $table->text('weaknesses')->nullable();
            $table->text('missing_skills')->nullable();
            $table->text('recommendations')->nullable();
            $table->timestamps();
        });
    }

    public function test_cv_review_uses_local_python_analyzer_and_stores_result(): void
    {
        config([
            'services.cv_analyzer.url' => 'http://127.0.0.1:8001',
            'services.cv_external_ai.enabled' => false,
        ]);

        [$user, $resume] = $this->studentWithResume();

        Http::fake([
            '127.0.0.1:8001/analyze-cv' => Http::response($this->localResult(), 200),
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/cv-review')
            ->assertOk()
            ->assertJsonPath('overall_score', 78)
            ->assertJsonPath('ats_score', 82)
            ->assertJsonPath('analysis_source', 'local_python')
            ->assertJsonPath('ai_enhancement.status', 'disabled');

        $this->assertDatabaseHas('resume_analysis', [
            'resume_id' => $resume->id,
            'cv_score' => 78,
            'ats_score' => 82,
        ]);
    }

    public function test_cv_review_returns_controlled_error_when_python_service_is_unreachable(): void
    {
        config([
            'services.cv_analyzer.url' => 'http://127.0.0.1:8001',
            'services.cv_external_ai.enabled' => false,
        ]);

        [$user] = $this->studentWithResume();

        Http::fake([
            '127.0.0.1:8001/analyze-cv' => fn () => throw new ConnectionException('Connection refused'),
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/cv-review')
            ->assertStatus(503)
            ->assertJsonPath('message', 'CV analysis service is temporarily unavailable. Please try again.');
    }

    public function test_external_ai_failure_does_not_fail_local_cv_review(): void
    {
        config([
            'services.cv_analyzer.url' => 'http://127.0.0.1:8001',
            'services.cv_external_ai.enabled' => true,
            'services.ai_provider' => 'groq',
            'services.groq.keys' => ['test-key'],
        ]);

        [$user] = $this->studentWithResume();

        Http::fake([
            '127.0.0.1:8001/analyze-cv' => Http::response($this->localResult(), 200),
            'api.groq.com/*' => Http::response(['error' => 'rate limited'], 429),
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/cv-review')
            ->assertOk()
            ->assertJsonPath('overall_score', 78)
            ->assertJsonPath('ai_enhancement.status', 'unavailable');
    }

    public function test_invalid_local_analyzer_response_is_handled(): void
    {
        config([
            'services.cv_analyzer.url' => 'http://127.0.0.1:8001',
            'services.cv_external_ai.enabled' => false,
        ]);

        [$user] = $this->studentWithResume();

        Http::fake([
            '127.0.0.1:8001/analyze-cv' => Http::response(['unexpected' => true], 200),
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/cv-review')
            ->assertStatus(503)
            ->assertJsonPath('message', 'CV analysis service returned an invalid response. Please try again.');
    }

    private function studentWithResume(): array
    {
        $user = User::factory()->create([
            'role' => 'Student',
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'university' => 'Example University',
            'major' => 'Computer Science',
            'phone' => '0599000000',
            'github' => 'https://github.com/student',
            'bio' => 'Computer science student focused on Laravel and React.',
        ]);

        $resume = Resume::create([
            'student_id' => $student->id,
            'title' => 'My Resume',
            'template' => 'executive',
            'full_name' => 'Student User',
            'professional_title' => 'Software Developer',
            'summary' => 'Computer science student focused on Laravel, React, REST APIs, and database-backed web applications.',
            'skills' => [
                ['name' => 'PHP'],
                ['name' => 'Laravel'],
                ['name' => 'React.js'],
                ['name' => 'MySQL'],
            ],
            'education' => [
                ['degree' => 'BS Computer Science', 'university' => 'Example University'],
            ],
            'experience' => [],
            'projects' => [
                [
                    'name' => 'Career Platform',
                    'description' => 'Developed a Laravel and React platform with REST APIs and MySQL.',
                ],
            ],
            'certificates' => [],
            'languages' => [],
            'file_path' => 'resumes/student.pdf',
        ]);

        return [$user, $resume];
    }

    private function localResult(): array
    {
        return [
            'overall_score' => 78,
            'ats_score' => 82,
            'level' => 'Good',
            'strengths' => ['Strong technical skills section'],
            'weaknesses' => ['No measurable achievements are mentioned.'],
            'recommendations' => ['Add measurable results where they are true.'],
            'section_scores' => [
                'completeness' => 17,
                'summary' => 10,
                'skills' => 18,
                'experience_projects' => 15,
                'ats' => 10,
                'consistency' => 8,
            ],
            'analysis_source' => 'local_python',
        ];
    }
}
