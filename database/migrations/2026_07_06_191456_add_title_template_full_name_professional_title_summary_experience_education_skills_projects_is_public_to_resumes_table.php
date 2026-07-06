<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->string('title')->default('My Resume')->after('student_id');
            $table->string('template')->default('executive')->after('title');
            $table->string('full_name')->nullable()->after('template');
            $table->string('professional_title')->nullable()->after('full_name');
            $table->text('summary')->nullable()->after('professional_title');
            $table->json('experience')->nullable()->after('summary');
            $table->json('education')->nullable()->after('experience');
            $table->json('skills')->nullable()->after('education');
            $table->json('projects')->nullable()->after('skills');
            $table->boolean('is_public')->default(false)->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'template',
                'full_name',
                'professional_title',
                'summary',
                'experience',
                'education',
                'skills',
                'projects',
                'is_public'
            ]);
        });
    }
};