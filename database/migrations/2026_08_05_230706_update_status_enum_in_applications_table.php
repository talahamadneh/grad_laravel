<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('applications')
            ->where('status', 'Screening')
            ->update(['status' => 'Under Review']);

        DB::table('applications')
            ->where('status', 'Offer')
            ->update(['status' => 'Accepted']);

        DB::table('applications')
            ->where('status', 'Hired')
            ->update(['status' => 'Accepted']);

        DB::statement("ALTER TABLE applications MODIFY status ENUM(
            'Applied',
            'Under Review',
            'Shortlisted',
            'Interview',
            'Accepted',
            'Rejected'
        )");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE applications MODIFY status ENUM(
            'Applied',
            'Under Review',
            'Shortlisted',
            'Interview',
            'Accepted',
            'Rejected'
        )");
    }
};