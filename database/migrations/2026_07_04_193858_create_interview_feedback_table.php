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
        Schema::create('interview_feedback', function (Blueprint $table) {
    $table->id();

    $table->foreignId('interview_id')->constrained()->cascadeOnDelete();

    $table->decimal('technical_score',5,2)->nullable();
    $table->decimal('communication_score',5,2)->nullable();

    $table->text('notes')->nullable();

    $table->enum('final_decision', ['Accepted','Rejected'])->nullable();

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interview_feedback');
    }
};
