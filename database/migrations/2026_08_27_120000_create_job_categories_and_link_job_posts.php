<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        if (!Schema::hasColumn('job_posts', 'category_id')) {
            Schema::table('job_posts', function (Blueprint $table) {
                $table->foreignId('category_id')
                    ->nullable()
                    ->after('company_id')
                    ->constrained('job_categories')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('job_posts', 'department')) {
            return;
        }

        DB::table('job_posts')
            ->whereNotNull('department')
            ->where('department', '<>', '')
            ->select('department')
            ->distinct()
            ->orderBy('department')
            ->each(function ($row) {
                $name = preg_replace('/\s+/', ' ', trim((string) $row->department)) ?: '';
                if ($name === '') {
                    return;
                }

                $existing = DB::table('job_categories')
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->first();

                $categoryId = $existing?->id;
                if (!$categoryId) {
                    $baseSlug = Str::slug($name) ?: 'category';
                    $slug = $baseSlug;
                    $suffix = 2;
                    while (DB::table('job_categories')->where('slug', $slug)->exists()) {
                        $slug = $baseSlug . '-' . $suffix++;
                    }

                    $categoryId = DB::table('job_categories')->insertGetId([
                        'name' => $name,
                        'slug' => $slug,
                        'description' => null,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('job_posts')
                    ->where('department', $row->department)
                    ->whereNull('category_id')
                    ->update(['category_id' => $categoryId]);
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('job_posts', 'category_id')) {
            Schema::table('job_posts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('category_id');
            });
        }

        Schema::dropIfExists('job_categories');
    }
};
