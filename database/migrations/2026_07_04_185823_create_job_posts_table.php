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
    Schema::create('job_posts', function (Blueprint $table) {
        $table->id();

        $table->foreignId('company_id')
            ->constrained()
            ->cascadeOnDelete();

        $table->string('title');
        $table->text('description')->nullable();

        $table->decimal('salary', 10, 2)->nullable();

        $table->enum('employment_type', [
            'Full-Time',
            'Part-Time',
            'Internship',
            'Freelance'
        ])->nullable();

        $table->string('location')->nullable();

        $table->date('deadline')->nullable();

        $table->unsignedInteger('vacancies')->default(1);

        $table->enum('status', [
            'Open',
            'Closed'
        ])->default('Open');

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_posts');
    }
};
