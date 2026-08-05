<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_status_history', function (Blueprint $table) {
            $table->enum('status', [
                'Applied',
                'Screening',
                'Shortlisted',
                'Interview',
                'Offer',
                'Accepted',
                'Hired',
                'Rejected',
            ])->change();
        });
    }

    public function down(): void
    {
        Schema::table('application_status_history', function (Blueprint $table) {
            $table->enum('status', [
                'Applied',
                'Screening',
                'Shortlisted',
                'Interview',
                'Offer',
                'Hired',
                'Rejected',
            ])->change();
        });
    }
};
