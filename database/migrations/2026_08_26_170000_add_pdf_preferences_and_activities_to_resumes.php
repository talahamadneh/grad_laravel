<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->boolean('include_profile_photo')->default(true);
            $table->json('activities')->nullable();
            $table->json('achievements')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->dropColumn(['include_profile_photo', 'activities', 'achievements']);
        });
    }
};
