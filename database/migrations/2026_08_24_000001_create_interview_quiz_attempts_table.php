<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('job_posts')->cascadeOnDelete();
            $table->json('questions');
            $table->json('answers')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_quiz_attempts');
    }
};
