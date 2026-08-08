<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experience', function (Blueprint $table) {
            $table->string('start_date', 50)->nullable()->change();
            $table->string('end_date', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('experience', function (Blueprint $table) {
            $table->date('start_date')->nullable()->change();
            $table->date('end_date')->nullable()->change();
        });
    }
};