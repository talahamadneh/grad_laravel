<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->enum('status', [
                'Applied',
                'Screening',
                'Shortlisted',
                'Interview',
                'Offer',
                'Accepted',
                'Hired',
                'Rejected',
            ])->default('Applied')->change();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->enum('status', [
                'Applied',
                'Screening',
                'Shortlisted',
                'Interview',
                'Offer',
                'Hired',
                'Rejected',
            ])->default('Applied')->change();
        });
    }
};
