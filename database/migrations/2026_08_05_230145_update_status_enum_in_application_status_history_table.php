<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE application_status_history MODIFY status ENUM(
            'Applied',
            'Screening',
            'Interview',
            'Shortlisted',
            'Offer',
            'Hired',
            'Accepted',
            'Rejected'
        )");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE application_status_history MODIFY status ENUM(
            'Applied',
            'Screening',
            'Interview',
            'Shortlisted',
            'Offer',
            'Hired',
            'Rejected'
        )");
    }
};