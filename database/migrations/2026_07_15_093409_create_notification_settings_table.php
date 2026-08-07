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
        Schema::create('notification_settings', function (Blueprint $table) {

    $table->id();

    $table->foreignId('user_id')
        ->constrained()
        ->cascadeOnDelete();


    // Student notifications
    $table->boolean('application_updates')
        ->default(true);

    $table->boolean('interview_notifications')
        ->default(true);

    $table->boolean('job_recommendations')
        ->default(true);

    $table->boolean('messages')
        ->default(true);

    $table->boolean('profile_views')
        ->default(true);

    $table->boolean('resume_feedback')
        ->default(true);


    // Company notifications
    $table->boolean('company_applications')
        ->default(true);

    $table->boolean('company_interviews')
        ->default(true);

    $table->boolean('company_messages')
        ->default(true);

    $table->boolean('company_deadlines')
        ->default(true);

    $table->boolean('company_matches')
        ->default(true);


    // Weekly summary
    $table->boolean('weekly_application_summary')
        ->default(true);


    $table->timestamps();

});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
