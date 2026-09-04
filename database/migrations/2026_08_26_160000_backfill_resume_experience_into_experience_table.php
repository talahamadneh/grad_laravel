<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('resumes') || !Schema::hasTable('experience')) {
            return;
        }

        DB::table('resumes')
            ->whereNotNull('experience')
            ->orderByDesc('id')
            ->get()
            ->unique('student_id')
            ->each(function ($resume) {
                if (DB::table('experience')->where('student_id', $resume->student_id)->exists()) {
                    return;
                }

                $items = json_decode($resume->experience, true);

                if (!is_array($items)) {
                    return;
                }

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $position = trim((string) ($item['position'] ?? $item['title'] ?? ''));
                    $company = trim((string) ($item['company'] ?? ''));
                    $description = trim((string) ($item['description'] ?? ''));

                    if ($position === '' && $company === '' && $description === '') {
                        continue;
                    }

                    DB::table('experience')->insert([
                        'student_id' => $resume->student_id,
                        'position' => $position,
                        'company' => $company,
                        'start_date' => $item['start_date'] ?? null,
                        'end_date' => $item['end_date'] ?? null,
                        'description' => $description,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally non-destructive: migrated work history must not be deleted.
    }
};
