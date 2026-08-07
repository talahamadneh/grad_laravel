<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('privacy_settings', function (Blueprint $table) {
            $table->boolean('ai_candidate_matching')
                ->default(true)
                ->after('ai_resume_analysis');
        });
    }

    public function down(): void
    {
        Schema::table('privacy_settings', function (Blueprint $table) {
            $table->dropColumn('ai_candidate_matching');
        });
    }
};
