<?php

declare(strict_types=1);

namespace Tests\Unit\FinancialDashboard;

use App\Data\FinancialDashboard\DashboardPeriodData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DashboardPeriodDataTest extends TestCase
{
    #[Test]
    public function current_month_runs_from_its_first_day_through_the_business_date(): void
    {
        $period = DashboardPeriodData::currentMonth('2026-09-17');

        self::assertSame('current_month', $period->preset);
        self::assertSame('2026-09-01', $period->from);
        self::assertSame('2026-09-17', $period->to);
        self::assertSame(17, $period->days());
        self::assertSame('daily', $period->interval());
    }

    #[Test]
    public function previous_month_covers_the_whole_previous_calendar_month(): void
    {
        $period = DashboardPeriodData::previousMonth('2026-03-31');

        self::assertSame('2026-02-01', $period->from);
        self::assertSame('2026-02-28', $period->to);
        self::assertSame(28, $period->days());
    }

    #[Test]
    public function interval_adapts_to_the_inclusive_period_length(): void
    {
        self::assertSame('daily', DashboardPeriodData::custom('2026-01-01', '2026-01-31')->interval());
        self::assertSame(31, DashboardPeriodData::custom('2026-01-01', '2026-01-31')->days());
        self::assertSame('weekly', DashboardPeriodData::custom('2026-01-01', '2026-02-01')->interval());
        self::assertSame('weekly', DashboardPeriodData::custom('2026-01-01', '2026-04-03')->interval());
        self::assertSame(93, DashboardPeriodData::custom('2026-01-01', '2026-04-03')->days());
        self::assertSame('monthly', DashboardPeriodData::custom('2026-01-01', '2026-04-04')->interval());
    }

    #[Test]
    public function custom_period_rejects_an_inverted_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DashboardPeriodData::custom('2026-09-10', '2026-09-01');
    }
}
