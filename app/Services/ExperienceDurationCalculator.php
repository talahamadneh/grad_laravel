<?php

namespace App\Services;

use App\Models\Student;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

class ExperienceDurationCalculator
{
    /**
     * Calculate unique worked time. Overlapping jobs are merged so the same
     * calendar period is never counted twice.
     */
    public function forStudent(Student $student, ?DateTimeInterface $asOf = null): float
    {
        $student->loadMissing('experiences');

        return $this->fromExperiences($student->experiences, $asOf);
    }

    public function fromExperiences(iterable $experiences, ?DateTimeInterface $asOf = null): float
    {
        $today = CarbonImmutable::instance($asOf ?? now())->startOfDay();

        $periods = collect($experiences)
            ->map(function ($experience) use ($today) {
                $start = $this->parseDate(data_get($experience, 'start_date'), false, $today);
                $end = $this->parseDate(data_get($experience, 'end_date'), true, $today);

                if (!$start || !$end || $start->greaterThan($today)) {
                    return null;
                }

                $end = $end->min($today);

                return $end->lessThan($start) ? null : [$start, $end];
            })
            ->filter()
            ->sortBy(fn (array $period) => $period[0]->timestamp)
            ->values();

        $merged = new Collection();

        foreach ($periods as [$start, $end]) {
            $last = $merged->last();

            if (!$last || $start->greaterThan($last[1]->addDay())) {
                $merged->push([$start, $end]);
                continue;
            }

            if ($end->greaterThan($last[1])) {
                $merged->put($merged->count() - 1, [$last[0], $end]);
            }
        }

        $days = $merged->sum(
            fn (array $period) => $period[0]->diffInDays($period[1]) + 1
        );

        return round($days / 365.25, 1);
    }

    private function parseDate(mixed $value, bool $isEnd, CarbonImmutable $today): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        $value = trim((string) $value);

        if ($value === '') {
            return $isEnd ? $today : null;
        }

        if ($isEnd && in_array(strtolower($value), ['present', 'current', 'now', 'ongoing'], true)) {
            return $today;
        }

        foreach (['!Y-m-d', '!Y-m', '!m/Y', '!F Y', '!M Y', '!Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);
                if ($date !== false && $date->format(str_replace('!', '', $format)) === $value) {
                    if (!$isEnd || $format === '!Y-m-d') {
                        return $date->startOfDay();
                    }

                    return $date->endOfMonth()->startOfDay();
                }
            } catch (\Throwable) {
                // Try the next supported format.
            }
        }

        return null;
    }
}
