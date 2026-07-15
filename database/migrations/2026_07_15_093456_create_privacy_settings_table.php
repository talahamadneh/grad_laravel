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
        Schema::create('privacy_settings', function (Blueprint $table) {

    $table->id();


    $table->foreignId('user_id')
        ->constrained()
        ->cascadeOnDelete();


    $table->boolean('profile_visibility')
        ->default(true);


    $table->boolean('contact_visibility')
        ->default(false);


    $table->boolean('ai_resume_analysis')
        ->default(true);


    $table->timestamps();

});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('privacy_settings');
    }
};
