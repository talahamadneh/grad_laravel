<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
  public function up(): void
    {
        Schema::table('job_posts', function (Blueprint $table) {
            $table->text('about')->nullable()->after('description');
            $table->text('responsibilities')->nullable()->after('about');
            $table->text('requirements')->nullable()->after('responsibilities');
        });
    }

    public function down(): void
    {
        Schema::table('job_posts', function (Blueprint $table) {
            $table->dropColumn([
                'about',
                'responsibilities',
                'requirements'
            ]);
        });
    }
};
