<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\JobPost;
use App\Models\Resume;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminJobApplicantsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('company_name');
            $table->timestamps();
        });

        Schema::create('job_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('avatar')->nullable();
            $table->string('headline')->nullable();
            $table->timestamps();
        });

        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->string('full_name')->nullable();
            $table->string('professional_title')->nullable();
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->foreignId('job_post_id');
            $table->foreignId('resume_id')->nullable();
            $table->string('status');
            $table->decimal('match_score', 5, 2)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_admin_receives_only_applicants_for_the_requested_job(): void
    {
        [$admin, $job, $otherJob] = $this->seedJobs();
        $application = $this->seedApplication($job, 'Sara', 82);
        $this->seedApplication($otherJob, 'Other Student', 40);

        $this->actingAs($admin)
            ->getJson("/api/admin/jobs/{$job->id}/applicants")
            ->assertOk()
            ->assertJsonPath('job.id', $job->id)
            ->assertJsonPath('applicants_count', 1)
            ->assertJsonCount(1, 'applicants')
            ->assertJsonPath('applicants.0.application_id', $application->id)
            ->assertJsonPath('applicants.0.job_id', $job->id)
            ->assertJsonPath('applicants.0.name', 'Sara')
            ->assertJsonPath('applicants.0.match_percentage', 82);
    }

    public function test_endpoint_supports_pagination_and_empty_results(): void
    {
        [$admin, $job] = $this->seedJobs();

        $this->actingAs($admin)
            ->getJson("/api/admin/jobs/{$job->id}/applicants?page=1&per_page=20")
            ->assertOk()
            ->assertJsonPath('applicants_count', 0)
            ->assertJsonPath('applicants.current_page', 1)
            ->assertJsonPath('applicants.per_page', 20)
            ->assertJsonPath('applicants.total', 0)
            ->assertJsonCount(0, 'applicants.data');
    }

    public function test_only_admin_can_access_job_applicants_and_missing_job_is_404(): void
    {
        [$admin, $job] = $this->seedJobs();
        $studentUser = User::factory()->create(['role' => 'student']);

        $this->actingAs($studentUser)
            ->getJson("/api/admin/jobs/{$job->id}/applicants")
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson('/api/admin/jobs/999999/applicants')
            ->assertNotFound();
    }

    private function seedJobs(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $companyUser = User::factory()->create(['role' => 'company']);
        $company = Company::create(['user_id' => $companyUser->id, 'company_name' => 'Acme']);

        return [
            $admin,
            JobPost::create(['company_id' => $company->id, 'title' => 'Backend Developer']),
            JobPost::create(['company_id' => $company->id, 'title' => 'Designer']),
        ];
    }

    private function seedApplication(JobPost $job, string $name, int $score): Application
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'student']);
        $student = Student::create([
            'user_id' => $user->id,
            'avatar' => '/storage/avatars/student.jpg',
            'headline' => 'Junior Backend Developer',
        ]);
        $resume = Resume::create([
            'student_id' => $student->id,
            'full_name' => $name,
            'professional_title' => 'Junior Backend Developer',
        ]);

        return Application::create([
            'student_id' => $student->id,
            'job_post_id' => $job->id,
            'resume_id' => $resume->id,
            'status' => 'Applied',
            'match_score' => $score,
            'applied_at' => '2026-08-27 10:00:00',
        ]);
    }
}
