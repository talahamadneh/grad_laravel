<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
    $table->id();

    $table->foreignId('student_id')->constrained()->cascadeOnDelete();
    $table->foreignId('job_post_id')->constrained('job_posts')->cascadeOnDelete();
    $table->foreignId('resume_id')->constrained()->cascadeOnDelete();

    $table->timestamp('applied_at')->useCurrent();

    $table->enum('status', [
        'Applied',
        'Under Review',
        'Shortlisted',
        'Interview',
        'Accepted',
        'Rejected'
    ])->default('Applied');

    $table->decimal('match_score',5,2)->nullable();

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
