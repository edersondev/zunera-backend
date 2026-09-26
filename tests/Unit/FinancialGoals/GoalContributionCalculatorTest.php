<?php

declare(strict_types=1);

namespace Tests\Unit\FinancialGoals;

use App\Services\FinancialGoals\GoalContributionCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class GoalContributionCalculatorTest extends TestCase
{
    public function test_future_months_are_inclusive_and_round_up_centavos(): void
    {
        $calculator = new GoalContributionCalculator;
        $today = CarbonImmutable::parse('2026-09-25', 'America/Sao_Paulo');
        $result = $calculator->calculate('2027-08-31', 1_200_001, 'active', $today);
        self::assertSame('future', $result['target_date_state']);
        self::assertSame(12, $result['contribution_periods_remaining']);
        self::assertSame(100_001, $result['suggested_monthly_centavos']);
        self::assertSame(340, $result['remaining_calendar_days']);
    }

    public function test_due_overdue_undated_funded_and_completed_have_no_suggestion(): void
    {
        $calculator = new GoalContributionCalculator;
        $today = CarbonImmutable::parse('2026-09-25', 'America/Sao_Paulo');
        foreach ([
            [null, 100, 'active', null],
            ['2026-09-25', 100, 'active', 'due_today'],
            ['2026-09-24', 100, 'active', 'overdue'],
            ['2027-01-01', 0, 'active', 'met'],
            ['2027-01-01', 100, 'completed', 'future'],
        ] as [$date, $remaining, $status, $state]) {
            $result = $calculator->calculate($date, $remaining, $status, $today);
            self::assertSame($state, $result['target_date_state']);
            self::assertNull($result['suggested_monthly_centavos']);
        }
    }
}
