<?php

namespace App\Services;

use App\Models\Student;

class StudentExperienceService
{
    public function sync(Student $student, array $items): void
    {
        $student->experiences()->delete();

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $position = trim((string) ($item['position'] ?? $item['title'] ?? ''));
            $company = trim((string) ($item['company'] ?? ''));
            $startDate = trim((string) ($item['start_date'] ?? ''));
            $endDate = trim((string) ($item['end_date'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));

            if ($position === '' && $company === '' && $description === '') {
                continue;
            }

            $student->experiences()->create([
                'position' => $position,
                'company' => $company,
                'start_date' => $startDate !== '' ? $startDate : null,
                'end_date' => $endDate !== '' ? $endDate : null,
                'description' => $description,
            ]);
        }

        $student->unsetRelation('experiences');
    }

    public function forResume(Student $student): array
    {
        $student->loadMissing('experiences');

        return $student->experiences
            ->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->position,
                'position' => $item->position,
                'company' => $item->company,
                'start_date' => $item->start_date,
                'end_date' => $item->end_date,
                'description' => $item->description,
            ])
            ->values()
            ->all();
    }
}
