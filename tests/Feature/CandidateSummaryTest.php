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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CandidateSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'application_status_history',
            'company_notes',
            'applications',
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
            $table->string('phone')->nullable();
            $table->text('avatar')->nullable();
            $table->decimal('gpa', 3, 2)->nullable();
            $table->string('portfolio')->nullable();
            $table->string('linkedin')->nullable();
            $table->string('github')->nullable();
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
            $table->string('template')->nullable();
            $table->string('full_name')->nullable();
            $table->string('professional_title')->nullable();
            $table->text('summary')->nullable();
            $table->json('skills')->nullable();
            $table->json('education')->nullable();
            $table->json('experience')->nullable();
            $table->json('projects')->nullable();
            $table->json('certificates')->nullable();
            $table->json('languages')->nullable();
            $table->decimal('total_years_experience', 4, 1)->nullable();
            $table->string('file_path')->nullable();
            $table->boolean('is_public')->default(false);
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

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->foreignId('job_post_id');
            $table->foreignId('resume_id');
            $table->timestamp('applied_at')->nullable();
            $table->string('status')->default('Applied');
            $table->decimal('match_score', 5, 2)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('match_analysis')->nullable();
            $table->string('match_source')->nullable();
            $table->timestamps();
        });

        Schema::create('company_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('application_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id');
            $table->string('status');
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_candidate_summary_uses_local_python_and_preserves_response_shape(): void
    {
        [$companyUser, $application] = $this->seedApplication();
        $this->fakeLocalServices(82, ['Laravel', 'PHP', 'MySQL'], ['REST APIs']);

        $response = $this->actingAs($companyUser)
            ->getJson("/api/company/applicants/{$application->id}/ai-summary")
            ->assertOk();

        $response->assertJsonPath('candidate', 'Jane Candidate')
            ->assertJsonPath('summary', 'Local deterministic summary.');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/match-job'));
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/candidate-summary')) {
                return false;
            }

            $payload = $request->data();

            return $payload['job_title'] === 'Junior Backend Developer'
                && $payload['match_percentage'] === 82
                && $payload['matching_skills'] === ['Laravel', 'PHP', 'MySQL']
                && $payload['missing_skills'] === ['REST APIs']
                && $payload['candidate_skills'] === ['Laravel', 'PHP', 'MySQL']
                && !str_contains(json_encode($payload), 'jane@example.test')
                && !str_contains(json_encode($payload), '0790000000')
                && !array_key_exists('notes', $payload);
        });
    }

    public function test_python_service_unavailable_returns_clean_503(): void
    {
        [$companyUser, $application] = $this->seedApplication();

        Http::fake([
            'http://127.0.0.1:8001/match-job' => Http::response($this->localResult(70), 200),
            'http://127.0.0.1:8001/candidate-summary' => fn () => throw new ConnectionException('connection refused'),
        ]);

        $this->actingAs($companyUser)
            ->getJson("/api/company/applicants/{$application->id}/ai-summary")
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Candidate summary service unavailable']);
    }

    public function test_company_cannot_summarize_another_company_applicant(): void
    {
        [, $application] = $this->seedApplication();
        $otherUser = User::create(['name' => 'Other Company', 'email' => 'other-company@example.test', 'role' => 'Company']);
        Company::create(['user_id' => $otherUser->id, 'company_name' => 'Other Co']);

        Http::fake([
            'http://127.0.0.1:8001/*' => Http::response(['unexpected' => true], 500),
        ]);

        $this->actingAs($otherUser)
            ->getJson("/api/company/applicants/{$application->id}/ai-summary")
            ->assertNotFound()
            ->assertJsonPath('message', 'Applicant not found');

        Http::assertNothingSent();
    }

    public function test_full_applicant_details_reuses_local_candidate_summary_and_keeps_match_shape(): void
    {
        [$companyUser, $application] = $this->seedApplication();
        $this->fakeLocalServices(64, ['Laravel'], ['PHP']);

        $response = $this->actingAs($companyUser)
            ->getJson("/api/company/applicants/{$application->id}/details")
            ->assertOk();

        $response->assertJsonPath('application_id', $application->id)
            ->assertJsonPath('match.percentage', 64)
            ->assertJsonPath('match.matching_skills.0', 'Laravel')
            ->assertJsonPath('match.missing_skills.0', 'PHP')
            ->assertJsonPath('ai_summary', 'Local deterministic summary.');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/candidate-summary'));
    }

    public function test_candidate_summary_does_not_call_gemini_or_groq(): void
    {
        [$companyUser, $application] = $this->seedApplication();
        $this->fakeLocalServices(82, ['Laravel'], []);

        $this->actingAs($companyUser)
            ->getJson("/api/company/applicants/{$application->id}/ai-summary")
            ->assertOk();

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.groq.com'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com'));
    }

    private function seedApplication(): array
    {
        $studentUser = User::create(['name' => 'Jane Candidate', 'email' => 'jane@example.test', 'role' => 'Student']);
        $companyUser = User::create(['name' => 'Company', 'email' => 'company@example.test', 'role' => 'Company']);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'major' => 'Computer Science',
            'headline' => 'Junior Laravel Developer',
            'phone' => '0790000000',
            'portfolio' => 'https://portfolio.example.test',
            'linkedin' => 'https://linkedin.example.test/jane',
            'github' => 'https://github.example.test/jane',
        ]);
        $company = Company::create(['user_id' => $companyUser->id, 'company_name' => 'Tech Co']);

        $resume = Resume::create([
            'student_id' => $student->id,
            'full_name' => 'Jane Candidate',
            'professional_title' => 'Junior Laravel Developer',
            'summary' => 'Laravel and MySQL developer.',
            'total_years_experience' => 1.5,
            'skills' => ['Laravel', 'PHP', 'MySQL'],
            'education' => [['field_of_study' => 'Computer Science']],
            'experience' => [['position' => 'Backend Intern', 'company' => 'Acme', 'description' => 'Built Laravel APIs.']],
            'projects' => [['name' => 'Career Platform', 'description' => 'Laravel MySQL platform.']],
        ]);

        $job = JobPost::create([
            'company_id' => $company->id,
            'title' => 'Junior Backend Developer',
            'description' => 'Build backend services.',
            'requirements' => 'Laravel PHP MySQL REST APIs.',
            'employment_type' => 'Full-Time',
            'level' => 'Junior',
            'location' => 'Amman',
            'required_major' => 'Computer Science',
            'min_experience_years' => 1,
            'max_experience_years' => 3,
            'status' => 'Open',
        ]);

        foreach (['Laravel', 'PHP', 'MySQL', 'REST APIs'] as $skillName) {
            $skill = Skill::create(['name' => $skillName]);
            $job->skills()->attach($skill->id);

            if ($skillName !== 'REST APIs') {
                $student->skills()->attach($skill->id);
            }
        }

        $application = Application::create([
            'student_id' => $student->id,
            'job_post_id' => $job->id,
            'resume_id' => $resume->id,
            'status' => 'Applied',
            'applied_at' => now(),
            'match_score' => 82,
            'match_source' => 'local_python',
            'match_analysis' => ['recommendation' => null],
        ]);

        return [$companyUser, $application];
    }

    private function fakeLocalServices(int $score, array $matchingSkills, array $missingSkills): void
    {
        Http::fake([
            'http://127.0.0.1:8001/match-job' => Http::response($this->localResult($score, $matchingSkills, $missingSkills), 200),
            'http://127.0.0.1:8001/candidate-summary' => Http::response(['summary' => 'Local deterministic summary.'], 200),
            'https://api.groq.com/*' => Http::response(['unexpected' => true], 500),
            'https://generativelanguage.googleapis.com/*' => Http::response(['unexpected' => true], 500),
        ]);
    }

    private function localResult(int $score, array $matchingSkills = ['Laravel'], array $missingSkills = []): array
    {
        return [
            'score' => $score,
            'level' => $score >= 75 ? 'Good Match' : 'Fair Match',
            'breakdown' => [
                'skills' => ['score' => 30, 'max_weight' => 45, 'applicable' => true],
            ],
            'matching_skills' => $matchingSkills,
            'missing_skills' => $missingSkills,
            'reasons' => $matchingSkills ? ['Matched required skills: ' . implode(', ', $matchingSkills)] : [],
            'warnings' => [],
        ];
    }
}
