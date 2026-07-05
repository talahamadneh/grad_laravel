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
       Schema::create('resume_analysis', function (Blueprint $table) {
    $table->id();
    $table->foreignId('resume_id')->constrained()->cascadeOnDelete();

    $table->integer('cv_score')->nullable();
    $table->integer('ats_score')->nullable();

    $table->text('strengths')->nullable();
    $table->text('weaknesses')->nullable();
    $table->text('missing_skills')->nullable();
    $table->text('recommendations')->nullable();

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resume_analysis');
    }
};
