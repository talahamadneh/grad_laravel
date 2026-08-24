<?php

namespace Tests\Feature;

use App\Models\Resume;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CVReviewRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_CV_RUNTIME_TEST') !== 'true') {
            $this->markTestSkipped('Set RUN_CV_RUNTIME_TEST=true and start python_ai FastAPI service to run this test.');
        }

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

    public function test_laravel_calls_running_python_cv_analyzer_without_external_ai(): void
    {
        config([
            'services.cv_analyzer.url' => 'http://127.0.0.1:8001',
            'services.cv_external_ai.enabled' => false,
            'services.ai_provider' => 'groq',
        ]);

        $health = Http::timeout(3)->get('http://127.0.0.1:8001/health');
        $this->assertTrue($health->successful(), 'Python CV analyzer is not reachable on 127.0.0.1:8001.');

        $user = User::factory()->create([
            'role' => 'Student',
            'name' => 'Runtime Student',
            'email' => 'runtime.student@example.com',
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'university' => 'Example University',
            'major' => 'Computer Science',
            'phone' => '0599000000',
            'github' => 'https://github.com/runtime-student',
            'linkedin' => 'https://linkedin.com/in/runtime-student',
            'bio' => 'Computer science student focused on Laravel, React, and APIs.',
            'headline' => 'Software Developer',
        ]);

        $resume = Resume::create([
            'student_id' => $student->id,
            'title' => 'Runtime Resume',
            'template' => 'executive',
            'full_name' => 'Runtime Student',
            'professional_title' => 'Software Developer',
            'summary' => 'Computer science student focused on Laravel, React, REST APIs, MySQL databases, and building practical web applications for junior software developer roles.',
            'skills' => ['PHP', 'Laravel Framework', 'React.js', 'Java Script', 'MySQL', 'Git', 'HTML', 'CSS'],
            'education' => [
                [
                    'degree' => 'BSc Computer Science',
                    'university' => 'Example University',
                    'field_of_study' => 'Computer Science',
                    'start_date' => '2022',
                    'end_date' => '2026',
                ],
            ],
            'experience' => [
                [
                    'title' => 'Web Development Intern',
                    'company' => 'Local Tech',
                    'description' => 'Developed and tested Laravel API features, integrated MySQL queries, and improved dashboard pages for internal users.',
                ],
            ],
            'projects' => [
                [
                    'name' => 'Career Platform',
                    'description' => 'Built a Laravel and React career platform with authentication, REST APIs, job applications, and candidate matching.',
                ],
            ],
            'certificates' => [
                ['name' => 'Laravel Basics', 'issuer' => 'Online Course', 'year' => '2025'],
            ],
            'languages' => [
                ['language' => 'English', 'level' => 'Intermediate'],
            ],
            'file_path' => 'resumes/runtime.pdf',
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/cv-review')
            ->assertOk()
            ->assertJsonPath('analysis_source', 'local_python')
            ->assertJsonPath('ai_enhancement.status', 'disabled')
            ->assertJsonStructure([
                'overall_score',
                'ats_score',
                'level',
                'strengths',
                'weaknesses',
                'recommendations',
                'section_scores',
                'analysis_source',
                'ai_enhancement',
            ]);

        $this->assertDatabaseHas('resume_analysis', [
            'resume_id' => $resume->id,
        ]);
    }
}
