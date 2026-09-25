<?php

declare(strict_types=1);

namespace Tests\Unit\RecurringTransactions;

use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Models\RecurringTransaction;
use App\Services\RecurringTransactions\RecurringScheduleCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecurringScheduleCalendarBoundaryTest extends TestCase
{
    #[Test]
    #[DataProvider('monthEndCases')]
    public function monthly_rules_fall_back_to_the_last_day_of_short_months(string $anchor, string $from, string $expected): void
    {
        $rule = $this->rule(RecurrenceFrequency::Monthly, $anchor);
        $calculator = app(RecurringScheduleCalculator::class);

        self::assertSame($expected, $calculator->firstEligibleOnOrAfter($rule, $from));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function monthEndCases(): iterable
    {
        // Anchor on the 31st and walk across years, including leap February.
        $anchor = CarbonImmutable::parse('2020-01-31');
        $cursor = $anchor;
        for ($i = 0; $i < 48; $i++) {
            $cursor = $cursor->addMonthNoOverflow();
            $expected = $cursor->endOfMonth()->toDateString();
            yield "day-31-{$cursor->format('Y-m')}" => [$anchor->toDateString(), $cursor->startOfMonth()->toDateString(), $expected];
        }

        // Anchor on the 29th, which only February 2020 can represent fully.
        $anchor = CarbonImmutable::parse('2020-02-29');
        $cursor = $anchor;
        for ($i = 0; $i < 48; $i++) {
            $cursor = $cursor->addMonthNoOverflow();
            $month = $cursor->startOfMonth();
            $day = min(29, $month->daysInMonth);
            $expected = $month->day($day)->toDateString();
            yield "day-29-{$cursor->format('Y-m')}" => [$anchor->toDateString(), $month->toDateString(), $expected];
        }

        // Anchor on the 30th.
        $anchor = CarbonImmutable::parse('2021-01-30');
        $cursor = $anchor;
        for ($i = 0; $i < 24; $i++) {
            $cursor = $cursor->addMonthNoOverflow();
            $month = $cursor->startOfMonth();
            $day = min(30, $month->daysInMonth);
            $expected = $month->day($day)->toDateString();
            yield "day-30-{$cursor->format('Y-m')}" => [$anchor->toDateString(), $month->toDateString(), $expected];
        }
    }

    #[Test]
    #[DataProvider('leapYearCases')]
    public function yearly_rules_handle_february_twenty_ninth_and_non_leap_years(string $anchor, string $from, string $expected): void
    {
        $rule = $this->rule(RecurrenceFrequency::Yearly, $anchor);
        $calculator = app(RecurringScheduleCalculator::class);

        self::assertSame($expected, $calculator->firstEligibleOnOrAfter($rule, $from));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function leapYearCases(): iterable
    {
        $anchor = '2020-02-29';
        $cases = [
            ['2020-02-29', '2021-01-01', '2021-02-28'],
            ['2020-02-29', '2022-01-01', '2022-02-28'],
            ['2020-02-29', '2023-01-01', '2023-02-28'],
            ['2020-02-29', '2024-01-01', '2024-02-29'],
            ['2020-02-29', '2025-01-01', '2025-02-28'],
            ['2020-02-29', '2028-01-01', '2028-02-29'],
            ['2020-02-29', '2030-01-01', '2030-02-28'],
        ];

        foreach ($cases as [$anchorDate, $from, $expected]) {
            yield "$anchorDate->$from" => [$anchorDate, $from, $expected];
        }
    }

    private function rule(RecurrenceFrequency $frequency, string $anchor): RecurringTransaction
    {
        return new RecurringTransaction([
            'frequency' => $frequency,
            'start_date' => $anchor,
            'end_date' => null,
            'eligibility_starts_on' => $anchor,
        ]);
    }
}
