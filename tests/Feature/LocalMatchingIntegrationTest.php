<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\JobPost;
use App\Models\Resume;
use App\Models\Skill;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocalMatchingIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'notification_settings',
            'notifications',
            'applications',
            'saved_jobs',
            'job_skills',
            'student_skills',
            'skills',
            'job_posts',
            'resumes',
            'companies',
            'education',
            'students',
            'users',
        ] as $table) {
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
            $table->string('major')->nullable();
            $table->string('university')->nullable();
            $table->string('location')->nullable();
            $table->string('preferred_employment_type')->nullable();
            $table->string('headline')->nullable();
            $table->text('bio')->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('company_name');
            $table->timestamps();
        });

        Schema::create('education', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->string('university')->nullable();
            $table->string('degree')->nullable();
            $table->string('major')->nullable();
            $table->timestamps();
        });

        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->string('title')->nullable();
            $table->string('professional_title')->nullable();
            $table->text('summary')->nullable();
            $table->json('skills')->nullable();
            $table->json('education')->nullable();
            $table->json('experience')->nullable();
            $table->json('projects')->nullable();
            $table->decimal('total_years_experience', 4, 1)->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();
        });

        Schema::create('job_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->string('title');
            $table->string('department')->nullable();
            $table->text('description')->nullable();
            $table->text('responsibilities')->nullable();
            $table->text('requirements')->nullable();
            $table->decimal('salary', 10, 2)->nullable();
            $table->string('employment_type')->nullable();
            $table->string('level')->nullable();
            $table->string('work_mode')->nullable();
            $table->string('location')->nullable();
            $table->date('deadline')->nullable();
            $table->integer('vacancies')->default(1);
            $table->string('required_major')->nullable();
            $table->decimal('min_experience_years', 4, 1)->nullable();
            $table->decimal('max_experience_years', 4, 1)->nullable();
            $table->string('status')->default('Open');
            $table->json('moderation_issues')->nullable();
            $table->string('moderation_recommendation')->nullable();
            $table->timestamps();
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
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
            $table->id();
            $table->foreignId('student_id');
            $table->foreignId('job_post_id');
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->foreignId('job_post_id');
            $table->foreignId('resume_id');
            $table->timestamp('applied_at')->nullable();
            $table->string('status')->default('Applied');
            $table->decimal('match_score', 5, 2)->nullable();
            $table->json('match_analysis')->nullable();
            $table->string('match_source')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique();
            foreach ([
                'application_updates',
                'interview_notifications',
                'job_recommendations',
                'messages',
                'profile_views',
                'resume_feedback',
                'company_applications',
                'company_messages',
                'company_matches',
                'company_deadlines',
                'company_interviews',
                'weekly_application_summary',
            ] as $column) {
                $table->boolean($column)->default(true);
            }
            $table->timestamps();
        });
    }

    public function test_main_flows_use_same_local_python_score(): void
    {
        [$studentUser, $companyUser, $student, $job] = $this->seedMatchingData();
        $this->fakeMatcher(76);

        $jobs = $this->actingAs($studentUser)->getJson('/api/jobs')->assertOk();
        $jobs->assertJsonPath('data.0.match.match', 76)
            ->assertJsonPath('data.0.match.match_source', 'local_python')
            ->assertJsonPath('data.0.match.matching_skills.0', 'laravel');

        $recommended = $this->actingAs($studentUser)->getJson('/api/student/recommended-jobs')->assertOk();
        $recommended->assertJsonPath('0.match', 76)
            ->assertJsonPath('0.match_source', 'local_python');

        Application::create([
            'student_id' => $student->id,
            'job_post_id' => $job->id,
            'resume_id' => Resume::where('student_id', $student->id)->first()->id,
            'status' => 'Applied',
            'applied_at' => now(),
            'match_score' => 76,
            'match_source' => 'local_python',
            'match_analysis' => ['recommendation' => null],
        ]);

        $applicants = $this->actingAs($companyUser)->getJson('/api/company/applicants')->assertOk();
        $applicants->assertJsonPath('0.match', 76)
            ->assertJsonPath('0.matching_skills.0', 'laravel');

        $details = $this->actingAs($companyUser)->getJson("/api/company/jobs/{$job->id}")->assertOk();
        $details->assertJsonPath('recent_applicants.0.match', 76);
    }

    public function test_application_stores_local_python_snapshot(): void
    {
        [$studentUser,,,$job] = $this->seedMatchingData();
        $this->fakeMatcher(76);

        $this->actingAs($studentUser)->postJson("/api/jobs/{$job->id}/apply")
            ->assertCreated()
            ->assertJsonPath('application.match_score', '76.00')
            ->assertJsonPath('application.match_source', 'local_python');

        $this->assertDatabaseHas('applications', [
            'job_post_id' => $job->id,
            'match_score' => 76,
            'match_source' => 'local_python',
        ]);
    }

    public function test_python_unavailable_uses_local_php_fallback_without_external_ai(): void
    {
        [$studentUser] = $this->seedMatchingData();

        Http::fake([
            'http://127.0.0.1:8001/*' => Http::response(['down' => true], 500),
            'api.groq.com/*' => Http::response(['unexpected' => true], 500),
            'generativelanguage.googleapis.com/*' => Http::response(['unexpected' => true], 500),
        ]);

        $response = $this->actingAs($studentUser)->getJson('/api/jobs')->assertOk();
        $response->assertJsonPath('data.0.match.match_source', 'local_php_fallback');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/match-job'));
    }

    public function test_ai_job_recommendations_are_local_and_do_not_call_groq(): void
    {
        [$studentUser] = $this->seedMatchingData();
        $this->fakeMatcher(76);

        $response = $this->actingAs($studentUser)->getJson('/api/ai/job-recommendations')->assertOk();
        $response->assertJsonPath('0.match', 76)
            ->assertJsonPath('0.match_source', 'local_python')
            ->assertJsonStructure(['0' => ['why_it_fits', 'tip']]);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/match-jobs'));
    }

    private function seedMatchingData(): array
    {
        $studentUser = User::create(['name' => 'Student', 'email' => 'student@example.test', 'role' => 'Student']);
        $companyUser = User::create(['name' => 'Company', 'email' => 'company@example.test', 'role' => 'Company']);
        $student = Student::create(['user_id' => $studentUser->id, 'major' => 'Computer Engineering']);
        $company = Company::create(['user_id' => $companyUser->id, 'company_name' => 'Tech Co']);

        $resume = Resume::create([
            'student_id' => $student->id,
            'professional_title' => 'Junior Web Developer',
            'summary' => 'Laravel and MySQL developer.',
            'total_years_experience' => 1.5,
            'skills' => ['PHP', 'Laravel', 'MySQL'],
            'education' => [['field_of_study' => 'Computer Engineering']],
            'projects' => [['name' => 'Career Platform', 'description' => 'Laravel MySQL platform.']],
        ]);

        $job = JobPost::create([
            'company_id' => $company->id,
            'title' => 'Laravel Backend Developer',
            'description' => 'Build Laravel APIs.',
            'requirements' => 'Laravel PHP MySQL REST APIs.',
            'employment_type' => 'Full-Time',
            'level' => 'Junior',
            'work_mode' => 'On-site',
            'location' => 'Amman',
            'required_major' => 'Computer Engineering',
            'min_experience_years' => 1,
            'max_experience_years' => 3.5,
            'status' => 'Open',
        ]);

        foreach (['Laravel', 'PHP', 'MySQL', 'REST APIs'] as $skillName) {
            $skill = Skill::create(['name' => $skillName]);
            $job->skills()->attach($skill->id);
        }

        return [$studentUser, $companyUser, $student, $job, $resume];
    }

    private function fakeMatcher(int $score): void
    {
        Http::fake([
            'http://127.0.0.1:8001/match-jobs' => function ($request) use ($score) {
                return Http::response([
                    'results' => collect($request['jobs'] ?? [])
                        ->map(fn ($job) => array_merge([
                            'job_id' => $job['id'] ?? null,
                        ], $this->localResult($score)))
                        ->values()
                        ->all(),
                ], 200);
            },
            'http://127.0.0.1:8001/match-job' => Http::response($this->localResult($score), 200),
            'api.groq.com/*' => Http::response(['unexpected' => true], 500),
            'generativelanguage.googleapis.com/*' => Http::response(['unexpected' => true], 500),
        ]);
    }

    private function localResult(int $score): array
    {
        return [
            'score' => $score,
            'level' => 'Good Match',
            'breakdown' => [
                'skills' => ['score' => 33.75, 'max_weight' => 45, 'applicable' => true],
            ],
            'matching_skills' => ['laravel', 'php', 'mysql'],
            'missing_skills' => ['rest api'],
            'reasons' => ['Matched required skills: laravel, php, mysql'],
            'warnings' => [],
        ];
    }
}
