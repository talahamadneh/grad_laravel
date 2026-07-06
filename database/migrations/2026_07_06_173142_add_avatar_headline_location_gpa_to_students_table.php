<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('user_id');
            $table->string('headline')->nullable()->after('bio');
            $table->string('location')->nullable()->after('headline');
            $table->decimal('gpa', 3, 2)->nullable()->after('graduation_year');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['avatar', 'headline', 'location', 'gpa']);
        });
    }
};