<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialDashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialDashboard\FinancialDashboardFixtures;
use Tests\TestCase;

final class DashboardEvolutionTest extends TestCase
{
    use FinancialDashboardFixtures;
    use RefreshDatabase;

    #[Test]
    public function short_custom_periods_use_daily_intervals_with_exact_totals(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user);

        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 150_000,
            'transaction_date' => '2026-09-02',
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 40_000,
            'transaction_date' => '2026-09-03',
        ]);

        $response = $this->getJson('/api/v1/financial-dashboard/evolution?preset=custom&from=2026-09-01&to=2026-09-04')
            ->assertOk()
            ->assertJsonPath('data.interval', 'daily');

        self::assertCount(4, $response->json('data.intervals'));
        $response->assertJsonPath('data.intervals.0.from', '2026-09-01')
            ->assertJsonPath('data.intervals.0.is_partial', false)
            ->assertJsonPath('data.intervals.0.income.amount_centavos', 0)
            ->assertJsonPath('data.intervals.1.income.amount_centavos', 150_000)
            ->assertJsonPath('data.intervals.1.result.amount_centavos', 150_000)
            ->assertJsonPath('data.intervals.2.expenses.amount_centavos', 40_000)
            ->assertJsonPath('data.intervals.2.result.amount_centavos', -40_000)
            ->assertJsonPath('data.intervals.3.label', '04/09');
    }

    #[Test]
    public function mid_length_periods_use_weekly_intervals_marking_clipped_calendar_weeks(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user);

        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 90_000,
            'transaction_date' => '2026-09-09',
        ]);

        // 2026-09-03 is a Thursday; the first calendar week starts 2026-08-31.
        $response = $this->getJson('/api/v1/financial-dashboard/evolution?preset=custom&from=2026-09-03&to=2026-10-12')
            ->assertOk()
            ->assertJsonPath('data.interval', 'weekly');

        $intervals = $response->json('data.intervals');
        self::assertSame('2026-09-03', $intervals[0]['from']);
        self::assertSame('2026-09-06', $intervals[0]['to']);
        self::assertTrue($intervals[0]['is_partial']);
        self::assertSame('2026-10-12', end($intervals)['to']);
        self::assertTrue(end($intervals)['is_partial']);
        self::assertSame(90_000, $intervals[1]['income']['amount_centavos']);
        self::assertFalse($intervals[1]['is_partial']);
    }

    #[Test]
    public function long_periods_use_monthly_intervals_and_sum_only_realized_movements(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user);

        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 500_000,
            'transaction_date' => '2026-02-10',
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 120_000,
            'transaction_date' => '2026-03-15',
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 999_999,
            'transaction_date' => '2026-04-04',
            'removed_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/financial-dashboard/evolution?preset=custom&from=2026-01-01&to=2026-04-04')
            ->assertOk()
            ->assertJsonPath('data.interval', 'monthly');

        $intervals = $response->json('data.intervals');
        self::assertCount(4, $intervals);
        self::assertSame('01/2026', $intervals[0]['label']);
        self::assertFalse($intervals[0]['is_partial']);
        self::assertSame('04/2026', $intervals[3]['label']);
        self::assertSame('2026-04-01', $intervals[3]['from']);
        self::assertSame('2026-04-04', $intervals[3]['to']);
        self::assertTrue($intervals[3]['is_partial']);
        self::assertSame(500_000, $intervals[1]['income']['amount_centavos']);
        self::assertSame(500_000, $intervals[1]['result']['amount_centavos']);
        self::assertSame(120_000, $intervals[2]['expenses']['amount_centavos']);
        self::assertSame(-120_000, $intervals[2]['result']['amount_centavos']);
        self::assertSame(0, $intervals[3]['expenses']['amount_centavos']);
    }
}
