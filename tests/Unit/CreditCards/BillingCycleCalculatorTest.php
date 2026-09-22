<?php

declare(strict_types=1);

namespace Tests\Unit\CreditCards;

use App\Data\CreditCards\BillingCycleData;
use App\Services\CreditCards\BillingCycleCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BillingCycleCalculatorTest extends TestCase
{
    private BillingCycleCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new BillingCycleCalculator;
    }

    /** @return array<string, array{0: int, 1: string, 2: string, 3: string}> */
    public static function closingDayCases(): array
    {
        $cases = [];
        foreach (range(1, 31) as $closingDay) {
            $closingThisMonth = min($closingDay, 31);
            $cases["closing day {$closingDay}"] = [
                $closingDay,
                sprintf('2026-01-%02d', $closingThisMonth),
                sprintf('2026-01-%02d', $closingThisMonth),
                sprintf('2026-02-%02d', min($closingDay, 28)),
            ];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('closingDayCases')]
    public function closing_day_is_inclusive_and_next_calendar_day_moves_to_the_following_cycle(int $closingDay, string $purchaseOnClosing, string $expectedClosing, string $expectedNextClosing): void
    {
        $onClosing = $this->calculator->cycleForDate($purchaseOnClosing, $closingDay, 31);
        $this->assertSame($expectedClosing, $onClosing->closingDate);
        $this->assertSame($expectedClosing, $onClosing->periodTo);

        $afterClosing = $this->calculator->cycleForDate(
            CarbonImmutable::parse($expectedClosing)->addDay()->toDateString(),
            $closingDay,
            31,
        );
        $this->assertSame($expectedNextClosing, $afterClosing->closingDate);
        $this->assertSame($expectedClosing, CarbonImmutable::parse($afterClosing->periodFrom)->subDay()->toDateString());
    }

    /** @return array<string, array{0: int, 1: string, 2: string}> */
    public static function monthEndClampCases(): array
    {
        $cases = [];
        foreach (['2026-01', '2026-02', '2026-04', '2026-12', '2027-02', '2028-02', '2028-01', '2028-12'] as $month) {
            $cases[$month] = [
                (int) CarbonImmutable::parse($month.'-01')->daysInMonth,
                $month.'-15',
                $month.'-'.str_pad((string) CarbonImmutable::parse($month.'-01')->daysInMonth, 2, '0', STR_PAD_LEFT),
            ];
        }

        return $cases + [
            'leap february day 29' => [29, '2028-02-01', '2028-02-29'],
            'non leap february clamps to 28' => [31, '2026-02-01', '2026-02-28'],
            'leap february clamps to 29' => [31, '2028-02-01', '2028-02-29'],
            'month with thirty days' => [31, '2026-04-01', '2026-04-30'],
            'month with thirty one days' => [31, '2026-05-01', '2026-05-31'],
        ];
    }

    #[Test]
    #[DataProvider('monthEndClampCases')]
    public function configured_days_clamp_to_the_last_calendar_day_of_the_month(int $closingDay, string $purchaseDate, string $expectedClosing): void
    {
        $cycle = $this->calculator->cycleForDate($purchaseDate, $closingDay, $closingDay);

        $this->assertSame($expectedClosing, $cycle->closingDate);
        $this->assertLessThanOrEqual($closingDay, (int) CarbonImmutable::parse($expectedClosing)->day);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function dueDayCases(): array
    {
        $cases = [];
        foreach (range(1, 31) as $dueDay) {
            $cases["due day {$dueDay}"] = [$dueDay, $dueDay > 10 ? '2026-03-'.str_pad((string) $dueDay, 2, '0', STR_PAD_LEFT) : '2026-04-'.str_pad((string) $dueDay, 2, '0', STR_PAD_LEFT)];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('dueDayCases')]
    public function due_day_is_the_first_configured_occurrence_after_closing(int $dueDay, string $expectedDueDate): void
    {
        $cycle = $this->calculator->cycleForDate('2026-03-05', 10, $dueDay);

        $this->assertSame('2026-03-10', $cycle->closingDate);
        $this->assertSame($expectedDueDate, $cycle->dueDate);
        $this->assertTrue(CarbonImmutable::parse($cycle->dueDate)->greaterThan(CarbonImmutable::parse($cycle->closingDate)));
    }

    #[Test]
    public function due_day_equal_to_closing_day_moves_to_the_next_month(): void
    {
        $cycle = $this->calculator->cycleForDate('2026-03-05', 25, 25);

        $this->assertSame('2026-03-25', $cycle->closingDate);
        $this->assertSame('2026-04-25', $cycle->dueDate);
    }

    #[Test]
    public function year_boundary_cycles_stay_predictable(): void
    {
        $december = $this->calculator->cycleForDate('2026-12-20', 25, 5);
        $this->assertSame('2026-12-25', $december->closingDate);
        $this->assertSame('2027-01-05', $december->dueDate);

        $january = $this->calculator->cycleForDate('2027-01-03', 25, 5);
        $this->assertSame('2027-01-25', $january->closingDate);
        $this->assertSame('2027-02-05', $january->dueDate);

        $afterDecemberClosing = $this->calculator->cycleForDate('2026-12-26', 25, 5);
        $this->assertSame('2027-01-25', $afterDecemberClosing->closingDate);
        $this->assertSame('2026-12-26', $afterDecemberClosing->periodFrom);
    }

    #[Test]
    public function consecutive_installments_walk_one_cycle_at_a_time_over_leap_february(): void
    {
        $first = $this->calculator->cycleForDate('2028-01-05', 10, 17);
        $second = $this->calculator->nextCycle($first, 10, 17);
        $third = $this->calculator->nextCycle($second, 10, 17);
        $fourth = $this->calculator->nextCycle($third, 10, 17);

        $this->assertSame('2028-01-10', $first->closingDate);
        $this->assertSame('2028-02-10', $second->closingDate);
        $this->assertSame('2028-03-10', $third->closingDate);
        $this->assertSame('2028-04-10', $fourth->closingDate);
        $this->assertSame('2028-01-11', $second->periodFrom);
        $this->assertSame('2028-02-11', $third->periodFrom);
        $this->assertSame('2028-01-17', $first->dueDate);
        $this->assertSame('2028-02-17', $second->dueDate);
    }

    #[Test]
    public function next_cycle_clamps_after_a_shorter_month(): void
    {
        $january = $this->calculator->cycleForDate('2026-01-15', 31, 31);
        $february = $this->calculator->nextCycle($january, 31, 31);
        $march = $this->calculator->nextCycle($february, 31, 31);

        $this->assertSame('2026-01-31', $january->closingDate);
        $this->assertSame('2026-02-28', $february->closingDate);
        $this->assertSame('2026-03-31', $march->closingDate);
        $this->assertSame('2026-02-01', $february->periodFrom);
        $this->assertSame('2026-03-01', $march->periodFrom);
    }

    #[Test]
    public function statements_stay_open_through_the_inclusive_closing_date(): void
    {
        $this->assertTrue($this->calculator->isOpenOn('2026-03-10', '2026-03-10'));
        $this->assertTrue($this->calculator->isOpenOn('2026-03-09', '2026-03-10'));
        $this->assertFalse($this->calculator->isOpenOn('2026-03-11', '2026-03-10'));
        $this->assertFalse($this->calculator->isOpenOn('2026-04-01', '2026-03-31'));
    }

    #[Test]
    public function every_closing_date_in_a_year_produces_a_period_that_starts_the_day_after_the_previous_closing(): void
    {
        $checked = 0;

        for ($closingDay = 1; $closingDay <= 31; $closingDay++) {
            $cycle = $this->calculator->cycleForDate('2026-06-15', $closingDay, 5);

            $periodFrom = CarbonImmutable::parse($cycle->periodFrom);
            $closing = CarbonImmutable::parse($cycle->closingDate);

            $previousMonth = $closing->subMonthNoOverflow()->startOfMonth();
            $previousClosing = $previousMonth->day(min($closingDay, $previousMonth->daysInMonth));
            $this->assertSame($previousClosing->addDay()->toDateString(), $periodFrom->toDateString());
            $this->assertTrue($periodFrom->lessThanOrEqualTo($closing));
            $this->assertTrue(CarbonImmutable::parse($cycle->dueDate)->greaterThan($closing));
            $checked++;
        }

        $this->assertSame(31, $checked);
    }

    #[Test]
    public function cycle_data_reports_the_inclusive_period_boundaries(): void
    {
        $cycle = $this->calculator->cycleForDate('2026-03-10', 10, 17);

        $this->assertInstanceOf(BillingCycleData::class, $cycle);
        $this->assertSame('2026-02-11', $cycle->periodFrom);
        $this->assertSame('2026-03-10', $cycle->periodTo);
        $this->assertSame('2026-03-10', $cycle->closingDate);
        $this->assertSame('2026-03-17', $cycle->dueDate);
        $this->assertSame([
            'period_from' => '2026-02-11',
            'period_to' => '2026-03-10',
            'closing_date' => '2026-03-10',
            'due_date' => '2026-03-17',
        ], $cycle->toArray());
    }
}
