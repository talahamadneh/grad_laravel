<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {

            if (!Schema::hasColumn('notification_settings', 'weekly_application_summary')) {
                $table->boolean('weekly_application_summary')
                    ->default(true);
            }

        });
    }


    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {

            if (Schema::hasColumn('notification_settings', 'weekly_application_summary')) {
                $table->dropColumn('weekly_application_summary');
            }

        });
    }
};