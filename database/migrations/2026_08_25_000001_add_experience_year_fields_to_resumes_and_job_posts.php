<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->decimal('total_years_experience', 4, 1)
                ->nullable()
                ->after('experience');
        });

        Schema::table('job_posts', function (Blueprint $table) {
            $table->decimal('min_experience_years', 4, 1)
                ->nullable()
                ->after('level');

            $table->decimal('max_experience_years', 4, 1)
                ->nullable()
                ->after('min_experience_years');
        });
    }

    public function down(): void
    {
        Schema::table('job_posts', function (Blueprint $table) {
            $table->dropColumn([
                'min_experience_years',
                'max_experience_years',
            ]);
        });

        Schema::table('resumes', function (Blueprint $table) {
            $table->dropColumn('total_years_experience');
        });
    }
};
