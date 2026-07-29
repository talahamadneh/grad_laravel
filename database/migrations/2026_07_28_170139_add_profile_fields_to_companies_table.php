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
        Schema::table('companies', function (Blueprint $table) {
            $table->string('company_size')->nullable()->after('location');
            $table->string('stage')->nullable()->after('company_size');
            $table->string('founded_year', 4)->nullable()->after('stage');
            $table->string('cover_image')->nullable()->after('logo');
            $table->boolean('is_verified')->default(false)->after('approval_status');
            $table->json('values')->nullable()->after('is_verified');
            $table->json('benefits')->nullable()->after('values');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'company_size',
                'stage',
                'founded_year',
                'cover_image',
                'is_verified',
                'values',
                'benefits',
            ]);
        });
    }
};
