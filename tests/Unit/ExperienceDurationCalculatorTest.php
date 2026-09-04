<?php

namespace Tests\Unit;

use App\Services\ExperienceDurationCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ExperienceDurationCalculatorTest extends TestCase
{
    public function test_it_calculates_experience_and_supports_present(): void
    {
        $years = (new ExperienceDurationCalculator())->fromExperiences([
            ['start_date' => '2023-01', 'end_date' => '2024-06'],
            ['start_date' => '2024-07', 'end_date' => 'Present'],
        ], CarbonImmutable::parse('2026-03-01'));

        $this->assertSame(3.2, $years);
    }

    public function test_it_does_not_double_count_overlapping_experiences(): void
    {
        $years = (new ExperienceDurationCalculator())->fromExperiences([
            ['start_date' => '2023-01-01', 'end_date' => '2024-12-31'],
            ['start_date' => '2024-01-01', 'end_date' => '2024-12-31'],
        ], CarbonImmutable::parse('2025-01-01'));

        $this->assertSame(2.0, $years);
    }

    public function test_it_ignores_invalid_or_reversed_periods(): void
    {
        $years = (new ExperienceDurationCalculator())->fromExperiences([
            ['start_date' => 'invalid', 'end_date' => '2024-01'],
            ['start_date' => '2025-01', 'end_date' => '2024-01'],
        ], CarbonImmutable::parse('2026-01-01'));

        $this->assertSame(0.0, $years);
    }
}
