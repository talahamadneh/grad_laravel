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
    Schema::create('companies', function (Blueprint $table) {
        $table->id();

        $table->foreignId('user_id')
            ->constrained()
            ->cascadeOnDelete();

        $table->string('company_name');
        $table->string('industry')->nullable();
        $table->text('description')->nullable();

        $table->string('logo')->nullable();
        $table->string('website')->nullable();
        $table->string('phone')->nullable();
        $table->string('location')->nullable();

        $table->enum('approval_status', [
            'Pending',
            'Approved',
            'Rejected'
        ])->default('Pending');

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
