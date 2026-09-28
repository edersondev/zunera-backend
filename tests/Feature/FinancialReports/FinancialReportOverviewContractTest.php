<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialReports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FinancialReports\FinancialReportFixtures;
use Tests\TestCase;

final class FinancialReportOverviewContractTest extends TestCase
{
    use FinancialReportFixtures;
    use RefreshDatabase;

    #[Test]
    public function source_revision_changes_when_a_contribution_changes_without_changing_its_total(): void
    {
        $user = $this->reportSignIn();
        $account = $this->reportAccount($user);
        $category = $this->reportCategory($user);
        $transaction = $this->reportTransaction($user, $account, $category, 1_000, '2026-09-10');

        $before = $this->getJson('/api/v1/financial-reports')->assertOk()->json('data');
        self::assertNotEmpty($before['source_revision']);

        $transaction->update(['description' => 'Updated report label']);
        $after = $this->getJson('/api/v1/financial-reports')->assertOk()->json('data');
        self::assertSame($before['summary'], $after['summary']);
        self::assertNotSame($before['source_revision'], $after['source_revision']);
    }

    #[Test]
    public function effective_future_dated_custom_activity_reconciles_with_detail_and_dashboard(): void
    {
        $user = $this->reportSignIn();
        $account = $this->reportAccount($user);
        $category = $this->reportCategory($user, 'income');
        $this->reportTransaction($user, $account, $category, 12_345, '2026-10-05');
        $query = 'preset=custom&from=2026-10-01&to=2026-10-10';

        $overview = $this->getJson('/api/v1/financial-reports?'.$query)->assertOk()->json('data');
        $detail = $this->getJson('/api/v1/financial-reports/contributions?'.$query.'&metric=realized_income')->assertOk()->json('data');
        $dashboard = $this->getJson('/api/v1/financial-dashboard/summary?'.$query)->assertOk()->json('data');
        self::assertSame(12_345, $overview['summary']['realized_income']['amount_centavos']);
        self::assertSame($overview['summary']['realized_income'], $detail['total']);
        self::assertSame($overview['source_revision'], $detail['source_revision']);
        self::assertSame('2026-10-05', $detail['contributions'][0]['recognized_date']);
        self::assertSame($overview['summary']['realized_income'], $dashboard['realized_income']);
        self::assertSame('2026-09-26', $this->getJson('/api/v1/financial-reports')->assertOk()->json('data.scope.current_period.to'));
    }

    #[Test]
    public function weekly_partial_boundaries_and_zero_intervals_reconcile_under_one_revision(): void
    {
        $user = $this->reportSignIn('2026-10-01');
        $account = $this->reportAccount($user);
        $category = $this->reportCategory($user);
        $this->reportTransaction($user, $account, $category, 2_500, '2026-08-15');
        $query = 'preset=custom&from=2026-08-15&to=2026-09-30';

        $overview = $this->getJson('/api/v1/financial-reports?'.$query)->assertOk()->json('data');
        self::assertSame('week', $overview['evolution_granularity']);
        self::assertSame('2026-08-15', $overview['evolution'][0]['from']);
        self::assertTrue($overview['evolution'][0]['is_partial']);
        self::assertTrue($overview['evolution'][count($overview['evolution']) - 1]['is_partial']);
        self::assertContains(0, collect($overview['evolution'])->pluck('realized_expenses.amount_centavos')->all());
        self::assertSame(2_500, collect($overview['evolution'])->sum('realized_expenses.amount_centavos'));
        self::assertSame(2_500, collect($overview['expense_categories'])->sum('total.amount_centavos'));
        self::assertNotEmpty($overview['source_revision']);
    }

    #[Test]
    public function summary_intervals_categories_accounts_and_comparison_reconcile_to_centavo(): void
    {
        $user = $this->reportSignIn();
        $account = $this->reportAccount($user);
        $income = $this->reportCategory($user, 'income', ['name' => 'Salary']);
        $food = $this->reportCategory($user, 'expense', ['name' => 'Food']);
        $travel = $this->reportCategory($user, 'expense', ['name' => 'Travel']);
        $this->reportTransaction($user, $account, $income, 500_000, '2026-09-05');
        $this->reportTransaction($user, $account, $food, 300_000, '2026-09-12');
        $this->reportCardPurchase($user, $travel, 10_000, 1, '2026-09-05');
        $this->reportTransaction($user, $account, $income, 100_000, '2026-08-05');
        $this->reportTransaction($user, $account, $food, 50_000, '2026-08-12');
        $this->reportGoalActivity($user, $account, 'allocated', 2_000, '2026-09-15');
        $this->reportRecurringOccurrence($user, $account, $income, 3_000, '2026-09-15');

        $data = $this->getJson('/api/v1/financial-reports')->assertOk()->json('data');
        self::assertSame('current_month', $data['scope']['preset']);
        self::assertSame(['from' => '2026-09-01', 'to' => '2026-09-26', 'day_count' => 26], $data['scope']['current_period']);
        self::assertSame(['from' => '2026-08-01', 'to' => '2026-08-26', 'day_count' => 26], $data['scope']['previous_period']);
        self::assertSame(500_000, $data['summary']['realized_income']['amount_centavos']);
        self::assertSame(310_000, $data['summary']['realized_expenses']['amount_centavos']);
        self::assertSame(190_000, $data['summary']['financial_result']['amount_centavos']);
        self::assertSame(500_000, collect($data['evolution'])->sum('realized_income.amount_centavos'));
        self::assertSame(310_000, collect($data['evolution'])->sum('realized_expenses.amount_centavos'));
        self::assertSame(310_000, collect($data['expense_categories'])->sum('total.amount_centavos'));
        self::assertSame(500_000, collect($data['income_categories'])->sum('total.amount_centavos'));
        self::assertSame(10_000, $data['unattributed_card_expenses']['amount_centavos']);
        self::assertSame(140_000, $data['comparison']['financial_result']['difference']['amount_centavos']);
        self::assertSame(50_000, $data['comparison']['financial_result']['previous']['amount_centavos']);
        self::assertSame('available', $data['section_states']['summary']['status']);
        self::assertFalse($data['empty_states']['no_activity']);
    }

    #[Test]
    public function paid_card_refund_reconciles_category_and_evolution_without_payment_expense(): void
    {
        $user = $this->reportSignIn();
        $account = $this->reportAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);
        $food = $this->reportCategory($user, 'expense', ['name' => 'Food']);
        $purchase = $this->reportCardPurchase($user, $food, 10_000, 1, '2026-08-05');
        $this->reportPaidRefund($purchase, $account, 2_000, '2026-08-17', '2026-09-25', 'report-overview-refund');

        $data = $this->getJson('/api/v1/financial-reports?preset=historical_month&month=2026-08')->assertOk()->json('data');
        self::assertSame(8_000, $data['summary']['realized_expenses']['amount_centavos']);
        self::assertSame(8_000, collect($data['evolution'])->sum('realized_expenses.amount_centavos'));
        self::assertSame(8_000, $data['expense_categories'][0]['total']['amount_centavos']);
        self::assertSame($food->id, $data['expense_categories'][0]['category']['id']);
        self::assertSame('2026-07-01', $data['scope']['previous_period']['from']);
    }

    #[Test]
    public function empty_and_filtered_empty_states_are_available_zero_values(): void
    {
        $user = $this->reportSignIn();
        $account = $this->reportAccount($user);
        $category = $this->reportCategory($user, 'expense');
        $empty = $this->getJson('/api/v1/financial-reports')->assertOk()->json('data');
        self::assertSame(0, $empty['summary']['financial_result']['amount_centavos']);
        self::assertSame([], $empty['expense_categories']);
        self::assertTrue($empty['empty_states']['no_activity']);
        self::assertTrue($empty['empty_states']['no_previous_activity']);
        self::assertSame('available', $empty['section_states']['expense_categories']['status']);

        $this->reportTransaction($user, $account, $category, 1_000, '2026-09-20');
        $other = $this->reportAccount($user);
        $filtered = $this->getJson('/api/v1/financial-reports?account_id='.$other->id)->assertOk()->json('data');
        self::assertTrue($filtered['scope']['is_filtered']);
        self::assertTrue($filtered['empty_states']['no_filter_matches']);
        self::assertSame(0, $filtered['summary']['realized_expenses']['amount_centavos']);
    }

    #[Test]
    public function one_unavailable_section_is_null_while_reliable_summary_remains_usable(): void
    {
        $user = $this->reportSignIn();
        $account = $this->reportAccount($user);
        $category = $this->reportCategory($user, 'income');
        $this->reportTransaction($user, $account, $category, 1_000, '2026-09-20');

        // Corrupt account metadata only: monetary source rows remain readable.
        DB::table('financial_accounts')->where('id', $account->id)->update(['account_type' => 'invalid_type']);
        $data = $this->getJson('/api/v1/financial-reports')->assertOk()->json('data');

        self::assertSame(1_000, $data['summary']['realized_income']['amount_centavos']);
        self::assertSame('available', $data['section_states']['summary']['status']);
        self::assertSame('unavailable', $data['section_states']['accounts']['status']);
        self::assertNull($data['accounts']);
        self::assertNull($data['unattributed_card_expenses']);
        self::assertIsArray($data['evolution']);
    }

    #[Test]
    public function wholly_unavailable_report_returns_service_unavailable(): void
    {
        $this->reportSignIn();
        DB::partialMock()->shouldReceive('transaction')->andReturnUsing(static fn (callable $read): mixed => $read());
        DB::partialMock()->shouldReceive('query')->andThrow(new RuntimeException('Report reads failed.'));

        $this->getJson('/api/v1/financial-reports')
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'Financial report is temporarily unavailable. Retry the report.');
    }
}
