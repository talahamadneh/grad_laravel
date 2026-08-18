<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE job_posts
            MODIFY status
            ENUM('Open','Closed','Draft','Pending Review','Rejected','Changes Requested','Suspended')
            NOT NULL DEFAULT 'Pending Review'
        ");

        Schema::table('job_posts', function (Blueprint $table) {
            $table->unsignedTinyInteger('quality_score')->default(0)->after('status');
            $table->json('moderation_issues')->nullable()->after('quality_score');
            $table->string('moderation_recommendation')->nullable()->after('moderation_issues');
            $table->text('moderation_note')->nullable()->after('moderation_recommendation');
            $table->timestamp('moderated_at')->nullable()->after('moderation_note');
            $table->timestamp('reviewed_at')->nullable()->after('moderated_at');
        });
    }

    public function down(): void
    {
        DB::table('job_posts')
            ->whereIn('status', ['Pending Review', 'Rejected', 'Changes Requested', 'Suspended', 'Draft'])
            ->update(['status' => 'Closed']);

        Schema::table('job_posts', function (Blueprint $table) {
            $table->dropColumn([
                'quality_score',
                'moderation_issues',
                'moderation_recommendation',
                'moderation_note',
                'moderated_at',
                'reviewed_at',
            ]);
        });

        DB::statement("
            ALTER TABLE job_posts
            MODIFY status
            ENUM('Open','Closed')
            NOT NULL DEFAULT 'Open'
        ");
    }
};
