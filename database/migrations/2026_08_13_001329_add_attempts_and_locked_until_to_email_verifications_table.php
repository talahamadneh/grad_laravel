<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_verifications', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->after('code');
            $table->timestamp('locked_until')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_verifications', function (Blueprint $table) {
            $table->dropColumn([
                'attempts',
                'locked_until',
            ]);
        });
    }
};