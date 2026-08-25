<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobPost;
use App\Models\Resume;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExperienceYearsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'admin_activity_logs',
            'job_skills',
            'skills',
            'job_posts',
            'resumes',
            'companies',
            'students',
            'users',
        ] as $table) {
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
            $table->string('headline')->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('company_name');
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
            $table->decimal('total_years_experience', 4, 1)->nullable();
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

        Schema::create('job_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->string('title');
            $table->string('department')->nullable();
            $table->text('description')->nullable();
            $table->text('about')->nullable();
            $table->text('responsibilities')->nullable();
            $table->text('requirements')->nullable();
            $table->json('benefits')->nullable();
            $table->decimal('salary', 10, 2)->nullable();
            $table->string('employment_type')->nullable();
            $table->string('level')->nullable();
            $table->decimal('min_experience_years', 4, 1)->nullable();
            $table->decimal('max_experience_years', 4, 1)->nullable();
            $table->string('work_mode')->default('On-site');
            $table->string('location')->nullable();
            $table->date('deadline')->nullable();
            $table->unsignedInteger('vacancies')->default(1);
            $table->string('required_major')->nullable();
            $table->string('status')->default('Pending Review');
            $table->unsignedTinyInteger('quality_score')->default(0);
            $table->json('moderation_issues')->nullable();
            $table->string('moderation_recommendation')->nullable();
            $table->text('moderation_note')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
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

        Schema::create('admin_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type')->default('SYSTEM');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('description');
            $table->timestamps();
        });
    }

    public function test_resume_create_accepts_total_years_experience(): void
    {
        [$user] = $this->studentUser();

        $response = $this->actingAs($user)->postJson('/api/student/resume', $this->resumePayload([
            'total_years_experience' => 1.5,
        ]));

        $response->assertCreated()
            ->assertJsonPath('resume.total_years_experience', '1.5');

        $this->assertDatabaseHas('resumes', [
            'student_id' => $user->student->id,
            'total_years_experience' => 1.5,
        ]);
    }

    public function test_resume_update_accepts_total_years_experience(): void
    {
        [$user, $student] = $this->studentUser();
        $resume = Resume::create([
            'student_id' => $student->id,
            'title' => 'Old Resume',
            'template' => 'executive',
            'full_name' => 'Student User',
            'professional_title' => 'Developer',
        ]);

        $response = $this->actingAs($user)->putJson(
            "/api/student/resume/{$resume->id}",
            $this->resumePayload([
                'total_years_experience' => 3,
            ])
        );

        $response->assertOk()
            ->assertJsonPath('resume.total_years_experience', '3.0');

        $this->assertDatabaseHas('resumes', [
            'id' => $resume->id,
            'total_years_experience' => 3,
        ]);
    }

    public function test_resume_accepts_zero_and_decimal_years(): void
    {
        [$user, $student] = $this->studentUser();

        $zero = $this->actingAs($user)->postJson('/api/student/resume', $this->resumePayload([
            'full_name' => 'Zero Years',
            'total_years_experience' => 0,
        ]));

        $zero->assertCreated()
            ->assertJsonPath('resume.total_years_experience', '0.0');

        $decimalResume = Resume::create([
            'student_id' => $student->id,
            'title' => 'Decimal Resume',
            'template' => 'executive',
            'full_name' => 'Decimal Years',
            'professional_title' => 'Developer',
        ]);

        $decimal = $this->actingAs($user)->putJson(
            "/api/student/resume/{$decimalResume->id}",
            $this->resumePayload([
                'full_name' => 'Decimal Years',
                'total_years_experience' => 0.5,
            ])
        );

        $decimal->assertOk()
            ->assertJsonPath('resume.total_years_experience', '0.5');
    }

    public function test_resume_rejects_negative_total_years_experience(): void
    {
        [$user] = $this->studentUser();

        $this->actingAs($user)->postJson('/api/student/resume', $this->resumePayload([
            'total_years_experience' => -0.5,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_years_experience');
    }

    public function test_job_create_accepts_min_max_experience_and_required_major(): void
    {
        [$user, $company] = $this->companyUser();

        $response = $this->actingAs($user)->postJson('/api/company/jobs', $this->jobPayload([
            'min_experience_years' => 1,
            'max_experience_years' => 3.5,
            'required_major' => 'Computer Science',
        ]));

        $response->assertCreated()
            ->assertJsonPath('job.min_experience_years', '1.0')
            ->assertJsonPath('job.max_experience_years', '3.5')
            ->assertJsonPath('job.required_major', 'Computer Science');

        $this->assertDatabaseHas('job_posts', [
            'company_id' => $company->id,
            'min_experience_years' => 1,
            'max_experience_years' => 3.5,
            'required_major' => 'Computer Science',
        ]);
    }

    public function test_job_update_accepts_experience_range(): void
    {
        [$user, $company] = $this->companyUser();
        $job = $this->job($company);

        $response = $this->actingAs($user)->putJson("/api/company/jobs/{$job->id}", $this->jobPayload([
            'min_experience_years' => 2,
            'max_experience_years' => 4,
            'required_major' => 'Software Engineering',
        ]));

        $response->assertOk()
            ->assertJsonPath('job.min_experience_years', '2.0')
            ->assertJsonPath('job.max_experience_years', '4.0')
            ->assertJsonPath('job.required_major', 'Software Engineering');
    }

    public function test_job_create_and_update_accept_sixty_experience_years(): void
    {
        [$user, $company] = $this->companyUser();

        $created = $this->actingAs($user)->postJson('/api/company/jobs', $this->jobPayload([
            'min_experience_years' => 60,
            'max_experience_years' => 60,
        ]));

        $created->assertCreated()
            ->assertJsonPath('job.min_experience_years', '60.0')
            ->assertJsonPath('job.max_experience_years', '60.0');

        $job = $this->job($company);

        $updated = $this->actingAs($user)->putJson("/api/company/jobs/{$job->id}", $this->jobPayload([
            'min_experience_years' => 60,
            'max_experience_years' => 60,
        ]));

        $updated->assertOk()
            ->assertJsonPath('job.min_experience_years', '60.0')
            ->assertJsonPath('job.max_experience_years', '60.0');
    }

    public function test_job_rejects_experience_years_greater_than_sixty(): void
    {
        [$user, $company] = $this->companyUser();

        $this->actingAs($user)->postJson('/api/company/jobs', $this->jobPayload([
            'min_experience_years' => 60.5,
            'max_experience_years' => 61,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'min_experience_years',
                'max_experience_years',
            ]);

        $job = $this->job($company);

        $this->actingAs($user)->putJson("/api/company/jobs/{$job->id}", $this->jobPayload([
            'min_experience_years' => 60.5,
            'max_experience_years' => 61,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'min_experience_years',
                'max_experience_years',
            ]);
    }

    public function test_job_accepts_min_only_for_five_plus_years(): void
    {
        [$user, $company] = $this->companyUser();

        $response = $this->actingAs($user)->postJson('/api/company/jobs', $this->jobPayload([
            'min_experience_years' => 5,
            'max_experience_years' => null,
        ]));

        $response->assertCreated()
            ->assertJsonPath('job.min_experience_years', '5.0')
            ->assertJsonPath('job.max_experience_years', null);

        $this->assertDatabaseHas('job_posts', [
            'company_id' => $company->id,
            'min_experience_years' => 5,
            'max_experience_years' => null,
        ]);
    }

    public function test_job_rejects_max_experience_less_than_min(): void
    {
        [$user] = $this->companyUser();

        $this->actingAs($user)->postJson('/api/company/jobs', $this->jobPayload([
            'min_experience_years' => 3,
            'max_experience_years' => 1,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_experience_years');
    }

    public function test_job_accepts_null_experience_range(): void
    {
        [$user, $company] = $this->companyUser();

        $response = $this->actingAs($user)->postJson('/api/company/jobs', $this->jobPayload([
            'min_experience_years' => null,
            'max_experience_years' => null,
        ]));

        $response->assertCreated()
            ->assertJsonPath('job.min_experience_years', null)
            ->assertJsonPath('job.max_experience_years', null);

        $this->assertDatabaseHas('job_posts', [
            'company_id' => $company->id,
            'min_experience_years' => null,
            'max_experience_years' => null,
        ]);
    }

    public function test_existing_required_resume_and_job_payloads_still_work(): void
    {
        [$studentUser] = $this->studentUser();
        [$companyUser] = $this->companyUser();

        $this->actingAs($studentUser)->postJson('/api/student/resume', $this->resumePayload())
            ->assertCreated();

        $this->actingAs($companyUser)->postJson('/api/company/jobs', $this->jobPayload())
            ->assertCreated();
    }

    private function studentUser(): array
    {
        $user = User::factory()->create(['role' => 'Student']);
        $student = Student::create([
            'user_id' => $user->id,
            'headline' => 'Developer',
        ]);

        $user->setRelation('student', $student);

        return [$user, $student];
    }

    private function companyUser(): array
    {
        $user = User::factory()->create(['role' => 'Company']);
        $company = Company::create([
            'user_id' => $user->id,
            'company_name' => 'Example Company',
        ]);

        return [$user, $company];
    }

    private function resumePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'My Resume',
            'template' => 'executive',
            'full_name' => 'Student User',
            'professional_title' => 'Backend Developer',
            'summary' => 'Backend developer focused on Laravel APIs.',
            'experience' => [
                [
                    'title' => 'Developer Intern',
                    'company' => 'Example Company',
                    'start_date' => '2025-01',
                    'end_date' => '2025-06',
                    'description' => 'Built API features.',
                ],
            ],
            'education' => [],
            'skills' => [['name' => 'Laravel']],
            'projects' => [],
            'languages' => [],
            'certificates' => [],
            'is_public' => false,
        ], $overrides);
    }

    private function jobPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Backend Developer',
            'department' => 'Engineering',
            'employment_type' => 'Full-Time',
            'level' => 'Junior',
            'work_mode' => 'On-site',
            'location' => 'Amman',
            'salary' => 900,
            'description' => str_repeat('Build and maintain Laravel APIs for student career workflows. ', 3),
            'responsibilities' => 'Develop APIs, review code, and collaborate with product teams.',
            'requirements' => str_repeat('Laravel, PHP, MySQL, REST APIs, testing, and clear communication. ', 2),
            'skills' => ['Laravel', 'PHP', 'MySQL'],
            'benefits' => ['Health insurance'],
            'deadline' => now()->addMonth()->toDateString(),
            'vacancies' => 1,
            'required_major' => null,
        ], $overrides);
    }

    private function job(Company $company): JobPost
    {
        return JobPost::create([
            'company_id' => $company->id,
            'title' => 'Existing Job',
            'employment_type' => 'Full-Time',
            'work_mode' => 'On-site',
            'status' => 'Draft',
        ]);
    }
}
