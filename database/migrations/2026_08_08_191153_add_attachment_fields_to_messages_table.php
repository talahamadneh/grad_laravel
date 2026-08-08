<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('type')->default('text')->after('message');
            $table->string('file_url')->nullable()->after('type');
            $table->string('file_name')->nullable()->after('file_url');
            $table->string('file_type')->nullable()->after('file_name');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'file_url',
                'file_name',
                'file_type',
            ]);
        });
    }
};