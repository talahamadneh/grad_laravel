<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobPost;
use App\Models\Resume;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Smalot\PdfParser\Parser;
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
            'experience',
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
            $table->json('activities')->nullable();
            $table->json('achievements')->nullable();
            $table->boolean('include_profile_photo')->default(true);
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });

        Schema::create('experience', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->string('company')->nullable();
            $table->string('position')->nullable();
            $table->text('description')->nullable();
            $table->string('start_date', 50)->nullable();
            $table->string('end_date', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('job_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('category_id')->nullable();
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
            $table->softDeletes();
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

    public function test_resume_create_ignores_submitted_total_and_returns_calculated_experience(): void
    {
        [$user, $student] = $this->studentUser();

        $response = $this->actingAs($user)->postJson('/api/student/resume', $this->resumePayload([
            'total_years_experience' => 50,
            'experience' => [[
                'title' => 'Developer',
                'company' => 'Example Company',
                'start_date' => '2023-01-01',
                'end_date' => '2023-12-31',
                'description' => 'Built APIs.',
            ]],
        ]));

        $response->assertCreated()
            ->assertJsonPath('resume.total_years_of_experience', 1)
            ->assertJsonPath('resume.total_years_experience', 1);

        $this->assertDatabaseHas('resumes', [
            'student_id' => $user->student->id,
            'total_years_experience' => null,
        ]);
    }

    public function test_resume_update_does_not_overwrite_calculated_experience(): void
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
            ->assertJsonPath('resume.total_years_of_experience', 0.5)
            ->assertJsonPath('resume.total_years_experience', 0.5);

        $this->assertDatabaseHas('resumes', [
            'id' => $resume->id,
            'total_years_experience' => null,
        ]);
    }

    public function test_student_can_download_only_their_own_resume_as_pdf(): void
    {
        [$user, $student] = $this->studentUser();
        [$otherUser] = $this->studentUser();

        $resume = Resume::create([
            'student_id' => $student->id,
            'title' => 'My Resume',
            'template' => 'executive',
            'full_name' => 'Student User',
            'professional_title' => 'Backend Developer',
            'summary' => str_repeat('Laravel API developer building reliable systems and accessible products. ', 180),
            'experience' => [[
                'job_title' => 'Software Engineer',
                'company' => 'Golden Systems',
                'start_date' => '2023-01',
                'end_date' => '2025-03',
                'description' => 'Built production APIs.',
            ]],
            'education' => [[
                'degree' => 'Bachelor of Science',
                'university' => 'Example University',
                'field_of_study' => 'Computer Science',
                'start_year' => '2020',
                'end_year' => '2024',
            ]],
            'skills' => [['name' => 'Laravel', 'category' => 'Frameworks']],
            'projects' => [['name' => 'Graduate Platform', 'link' => 'https://example.test/project', 'description' => 'A career platform.']],
            'certificates' => [['name' => 'Laravel Certificate', 'issuer' => 'Example Academy', 'year' => '2025']],
            'languages' => [['language' => 'Arabic', 'proficiency' => 'Native']],
            'activities' => ['Programming club'],
            'achievements' => ['Graduation project award'],
            'include_profile_photo' => false,
        ]);

        $response = $this->actingAs($user)->get("/api/student/resume/{$resume->id}/pdf");

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('Student_User_resume.pdf');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $pdfText = (new Parser())->parseContent($response->getContent())->getText();
        foreach ([
            'Professional Experience', 'Total Experience:', 'Software Engineer', 'Golden Systems',
            'Education', 'Bachelor of Science', 'Example University', 'Computer Science',
            'Technical Skills', 'Laravel', 'Projects', 'Graduate Platform',
            'Additional Information', 'Laravel Certificate', 'Example Academy',
            'Arabic', 'Native', 'Programming club', 'Graduation project award',
        ] as $expectedText) {
            $this->assertStringContainsStringIgnoringCase($expectedText, $pdfText);
        }
        $this->assertStringContainsStringIgnoringCase('Total Experience: 2.2 Years', $pdfText);
        $this->assertGreaterThan(
            1,
            count((new Parser())->parseContent($response->getContent())->getPages()),
            'Long resume content should flow onto additional PDF pages.'
        );

        $this->actingAs($otherUser)
            ->getJson("/api/student/resume/{$resume->id}/pdf")
            ->assertNotFound();
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
