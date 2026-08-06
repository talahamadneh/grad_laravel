<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->boolean('company_applications')->default(true);
            $table->boolean('company_messages')->default(true);
            $table->boolean('company_matches')->default(true);
            $table->boolean('company_deadlines')->default(true);
            $table->boolean('company_interviews')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn([
                'company_applications',
                'company_messages',
                'company_matches',
                'company_deadlines',
                'company_interviews',
            ]);
        });
    }
};
