<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ProfileView;
use App\Models\SavedJob;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['profile_views', 'saved_jobs', 'applications', 'students', 'users'] as $table) {
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
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->unsignedBigInteger('job_post_id')->nullable();
            $table->unsignedBigInteger('resume_id')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('status')->default('Applied');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('saved_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->unsignedBigInteger('job_post_id');
        });

        Schema::create('profile_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('viewer_id')->nullable();
            $table->timestamps();
        });

        CarbonImmutable::setTestNow('2026-08-15 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_empty_dashboard_returns_zeroes_and_an_empty_activity_array(): void
    {
        [$user] = $this->studentUser();

        $this->actingAs($user)->getJson('/api/student/dashboard')
            ->assertOk()
            ->assertExactJson([
                'stats' => ['applications' => 0, 'interviews' => 0, 'saved' => 0, 'views' => 0],
                'application_activity' => [],
                'trends' => ['applications' => 0, 'interviews' => 0, 'profile_views' => 0],
            ]);
    }

    public function test_dashboard_is_scoped_to_authenticated_student_and_calculates_trends(): void
    {
        [$user, $student] = $this->studentUser();
        [$otherUser, $otherStudent] = $this->studentUser();

        $this->application($student, '2026-07-10', 'Applied', '2026-07-10');
        $this->application($student, '2026-08-02', 'Applied', '2026-08-02');
        $this->application($student, '2026-08-05', 'Interview', '2026-08-06');
        $this->application($student, '2026-08-09', 'Interview', '2026-08-10');
        $this->application($otherStudent, '2026-08-11', 'Interview', '2026-08-11');

        SavedJob::create(['student_id' => $student->id, 'job_post_id' => 1]);
        SavedJob::create(['student_id' => $student->id, 'job_post_id' => 2]);
        SavedJob::create(['student_id' => $otherStudent->id, 'job_post_id' => 3]);

        $this->profileView($user, '2026-07-03');
        $this->profileView($user, '2026-08-03');
        $this->profileView($user, '2026-08-04');
        $this->profileView($otherUser, '2026-08-05');

        $this->actingAs($user)->getJson('/api/student/dashboard')
            ->assertOk()
            ->assertExactJson([
                'stats' => ['applications' => 4, 'interviews' => 2, 'saved' => 2, 'views' => 3],
                'application_activity' => [
                    ['month' => 'Jul', 'applications' => 1],
                    ['month' => 'Aug', 'applications' => 3],
                ],
                'trends' => ['applications' => 200, 'interviews' => 100, 'profile_views' => 100],
            ]);
    }

    private function studentUser(): array
    {
        $user = User::factory()->create(['role' => 'Student']);
        $student = Student::create(['user_id' => $user->id]);

        return [$user, $student];
    }

    private function application(Student $student, string $appliedAt, string $status, string $updatedAt): void
    {
        Application::create([
            'student_id' => $student->id,
            'applied_at' => $appliedAt,
            'status' => $status,
        ])->forceFill([
            'created_at' => $appliedAt,
            'updated_at' => $updatedAt,
        ])->saveQuietly();
    }

    private function profileView(User $user, string $createdAt): void
    {
        ProfileView::create(['user_id' => $user->id])->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();
    }
}
