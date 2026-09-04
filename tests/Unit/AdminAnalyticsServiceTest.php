<?php

namespace Tests\Unit;

use App\Services\AdminAnalyticsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAnalyticsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('job_posts', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }

    public function test_jobs_over_time_uses_real_job_creation_dates_grouped_by_month(): void
    {
        DB::table('job_posts')->insert([
            ['created_at' => '2026-07-02 10:00:00', 'updated_at' => '2026-07-02 10:00:00'],
            ['created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-01 10:00:00'],
            ['created_at' => '2026-08-20 10:00:00', 'updated_at' => '2026-08-20 10:00:00'],
        ]);

        $this->assertSame([
            ['month' => 'Jul', 'year' => 2026, 'period' => '2026-07', 'count' => 1],
            ['month' => 'Aug', 'year' => 2026, 'period' => '2026-08', 'count' => 2],
        ], app(AdminAnalyticsService::class)->jobsOverTime());
    }
}
