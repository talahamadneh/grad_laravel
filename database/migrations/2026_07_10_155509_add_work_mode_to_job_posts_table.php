<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('job_posts', function (Blueprint $table) {
            $table->enum('work_mode', [
                'Remote',
                'Hybrid',
                'On-site'
            ])->after('employment_type')->default('On-site');
        });
    }

    public function down(): void
    {
        Schema::table('job_posts', function (Blueprint $table) {
            $table->dropColumn('work_mode');
        });
    }
};
