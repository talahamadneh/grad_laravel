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
                $items = json_decode($resume->experience, true);

                if (!is_array($items) || $items === []) {
                    return;
                }

                $latestExperienceUpdate = DB::table('experience')
                    ->where('student_id', $resume->student_id)
                    ->max('updated_at');

                if ($latestExperienceUpdate && $resume->updated_at <= $latestExperienceUpdate) {
                    return;
                }

                $rows = collect($items)
                    ->filter(fn ($item) => is_array($item))
                    ->map(function ($item) use ($resume) {
                        $position = trim((string) ($item['position'] ?? $item['title'] ?? ''));
                        $company = trim((string) ($item['company'] ?? ''));
                        $description = trim((string) ($item['description'] ?? ''));

                        if ($position === '' && $company === '' && $description === '') {
                            return null;
                        }

                        return [
                            'student_id' => $resume->student_id,
                            'position' => $position,
                            'company' => $company,
                            'start_date' => $item['start_date'] ?? null,
                            'end_date' => $item['end_date'] ?? null,
                            'description' => $description,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                if ($rows === []) {
                    return;
                }

                DB::transaction(function () use ($resume, $rows) {
                    DB::table('experience')->where('student_id', $resume->student_id)->delete();
                    DB::table('experience')->insert($rows);
                });
            });
    }

    public function down(): void
    {
        // Intentionally non-destructive.
    }
};
