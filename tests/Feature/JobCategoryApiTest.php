<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobCategory;
use App\Models\JobPost;
use App\Models\User;
use App\Services\AutomaticJobValidationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class JobCategoryApiTest extends TestCase
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

        Schema::create('job_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('job_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('category_id')->nullable();
            $table->string('title');
            $table->string('department')->nullable();
            $table->string('employment_type')->nullable();
            $table->string('level')->nullable();
            $table->decimal('min_experience_years', 4, 1)->nullable();
            $table->decimal('max_experience_years', 4, 1)->nullable();
            $table->string('work_mode')->nullable();
            $table->string('location')->nullable();
            $table->decimal('salary', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->text('responsibilities')->nullable();
            $table->text('requirements')->nullable();
            $table->json('benefits')->nullable();
            $table->date('deadline')->nullable();
            $table->unsignedInteger('vacancies')->default(1);
            $table->string('required_major')->nullable();
            $table->string('status')->nullable();
            $table->unsignedInteger('quality_score')->nullable();
            $table->json('moderation_issues')->nullable();
            $table->string('moderation_recommendation')->nullable();
            $table->text('moderation_note')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('job_skills', function (Blueprint $table) {
            $table->foreignId('job_post_id');
            $table->foreignId('skill_id');
        });
    }

    public function test_admin_can_list_categories_with_counts_and_meta(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $companyUser = User::factory()->create(['role' => 'company']);
        $company = Company::create(['user_id' => $companyUser->id, 'company_name' => 'Acme']);
        $category = JobCategory::create(['name' => 'Engineering', 'slug' => 'engineering']);
        JobPost::create(['company_id' => $company->id, 'category_id' => $category->id, 'title' => 'Developer']);

        $this->actingAs($admin)
            ->getJson('/api/admin/categories')
            ->assertOk()
            ->assertJsonPath('data.0.jobs_count', 1)
            ->assertJsonPath('meta.total_categories', 1)
            ->assertJsonPath('meta.categorized_jobs', 1);
    }

    public function test_admin_can_create_and_update_a_category_with_an_automatic_slug(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $created = $this->actingAs($admin)->postJson('/api/admin/categories', [
            'name' => 'Product Design',
            'description' => 'Design jobs',
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('category.slug', 'product-design');

        $id = $created->json('category.id');

        $this->actingAs($admin)->patchJson("/api/admin/categories/{$id}", [
            'name' => 'Product Engineering',
            'description' => null,
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('category.slug', 'product-engineering')
            ->assertJsonPath('category.is_active', false);
    }

    public function test_duplicate_category_name_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        JobCategory::create(['name' => 'Engineering', 'slug' => 'engineering']);

        $this->actingAs($admin)->postJson('/api/admin/categories', [
            'name' => ' Engineering ',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_non_admin_cannot_manage_categories(): void
    {
        $user = User::factory()->create(['role' => 'student']);

        $this->actingAs($user)
            ->getJson('/api/admin/categories')
            ->assertForbidden();
    }

    public function test_category_with_jobs_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $companyUser = User::factory()->create(['role' => 'company']);
        $company = Company::create(['user_id' => $companyUser->id, 'company_name' => 'Acme']);
        $category = JobCategory::create(['name' => 'Engineering', 'slug' => 'engineering']);
        JobPost::create(['company_id' => $company->id, 'category_id' => $category->id, 'title' => 'Developer']);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/categories/{$category->id}")
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Cannot delete a category that has jobs.']);
    }

    public function test_public_catalog_returns_only_active_categories(): void
    {
        $user = User::factory()->create(['role' => 'company']);
        JobCategory::create(['name' => 'Engineering', 'slug' => 'engineering', 'is_active' => true]);
        JobCategory::create(['name' => 'Legacy', 'slug' => 'legacy', 'is_active' => false]);

        $this->actingAs($user)
            ->getJson('/api/job-categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'engineering');
    }

    public function test_company_can_assign_category_when_creating_and_updating_a_job(): void
    {
        $companyUser = User::factory()->create(['role' => 'company']);
        Company::create(['user_id' => $companyUser->id, 'company_name' => 'Acme']);
        $engineering = JobCategory::create(['name' => 'Engineering', 'slug' => 'engineering']);
        $design = JobCategory::create(['name' => 'Design', 'slug' => 'design']);

        $this->mock(AutomaticJobValidationService::class)
            ->shouldReceive('apply')
            ->twice()
            ->andReturn([
                'status' => 'Open',
                'quality_score' => 100,
                'issues' => [],
                'recommendation' => 'Publish Automatically',
            ]);

        $payload = [
            'title' => 'Backend Developer',
            'category_id' => $engineering->id,
            'employment_type' => 'Full-Time',
            'work_mode' => 'Remote',
        ];

        $created = $this->actingAs($companyUser)
            ->postJson('/api/company/jobs', $payload)
            ->assertCreated()
            ->assertJsonPath('job.category_id', $engineering->id)
            ->assertJsonPath('job.category.slug', 'engineering');

        $jobId = $created->json('job.id');

        $this->actingAs($companyUser)
            ->putJson("/api/company/jobs/{$jobId}", array_merge($payload, [
                'category_id' => $design->id,
            ]))
            ->assertOk()
            ->assertJsonPath('job.category_id', $design->id)
            ->assertJsonPath('job.category.slug', 'design');

        $this->assertDatabaseHas('job_posts', [
            'id' => $jobId,
            'category_id' => $design->id,
            'department' => 'Design',
        ]);
    }

    public function test_migration_backfills_legacy_departments_without_losing_them(): void
    {
        Schema::drop('job_posts');
        Schema::drop('job_categories');

        Schema::create('job_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('department')->nullable();
            $table->timestamps();
        });

        $jobId = JobPost::query()->insertGetId([
            'title' => 'Legacy Developer',
            'department' => 'Engineering',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_27_120000_create_job_categories_and_link_job_posts.php');
        $migration->up();

        $category = JobCategory::where('name', 'Engineering')->firstOrFail();

        $this->assertDatabaseHas('job_posts', [
            'id' => $jobId,
            'department' => 'Engineering',
            'category_id' => $category->id,
        ]);
    }
}
