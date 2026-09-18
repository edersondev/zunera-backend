<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialDashboard;

use App\Enums\Transactions\TransactionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialDashboard\FinancialDashboardFixtures;
use Tests\TestCase;

final class DashboardSummaryTest extends TestCase
{
    use FinancialDashboardFixtures;
    use RefreshDatabase;

    #[Test]
    public function realized_totals_use_effective_non_removed_movements_inside_the_inclusive_period(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user, ['current_balance_centavos' => 250_000]);

        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 120_000,
            'transaction_date' => '2026-09-01',
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 45_000,
            'transaction_date' => '2026-09-15',
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 5_000,
            'transaction_date' => '2026-09-17',
        ]);

        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 30_000,
            'transaction_date' => '2026-08-31',
        ]);
        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 99_000,
            'transaction_date' => '2026-09-10',
            'status' => TransactionStatus::Pending,
        ]);
        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 88_000,
            'transaction_date' => '2026-09-10',
            'removed_at' => now(),
        ]);

        $this->getJson('/api/v1/financial-dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.period.preset', 'current_month')
            ->assertJsonPath('data.period.from', '2026-09-01')
            ->assertJsonPath('data.period.to', '2026-09-17')
            ->assertJsonPath('data.current_total_balance.amount_centavos', 250_000)
            ->assertJsonPath('data.realized_income.amount_centavos', 120_000)
            ->assertJsonPath('data.realized_expenses.amount_centavos', 50_000)
            ->assertJsonPath('data.financial_result.amount_centavos', 70_000);
    }

    #[Test]
    public function transfers_never_reach_realized_totals_or_the_combined_current_balance(): void
    {
        $user = $this->dashboardSignIn();
        $source = $this->dashboardAccount($user, ['current_balance_centavos' => 500_000]);
        $destination = $this->dashboardAccount($user, ['current_balance_centavos' => 0]);

        $this->getJson('/api/v1/financial-dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.current_total_balance.amount_centavos', 500_000);

        $this->dashboardTransfer($user, [
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 125_000,
            'transfer_date' => '2026-09-05',
        ], reconcile: true);

        $this->getJson('/api/v1/financial-dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.current_total_balance.amount_centavos', 500_000)
            ->assertJsonPath('data.realized_income.amount_centavos', 0)
            ->assertJsonPath('data.realized_expenses.amount_centavos', 0)
            ->assertJsonPath('data.financial_result.amount_centavos', 0);
    }

    #[Test]
    public function changing_the_period_moves_realized_values_while_the_current_balance_stays_current(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user, ['current_balance_centavos' => 300_000]);
        $other = $this->dashboardArchivedAccount($user, ['current_balance_centavos' => 999_000]);

        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 200_000,
            'transaction_date' => '2026-09-04',
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $other->id,
            'amount_centavos' => 50_000,
            'transaction_date' => '2026-08-12',
        ]);

        $this->getJson('/api/v1/financial-dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.current_total_balance.amount_centavos', 300_000)
            ->assertJsonPath('data.realized_income.amount_centavos', 200_000)
            ->assertJsonPath('data.realized_expenses.amount_centavos', 0);

        $this->getJson('/api/v1/financial-dashboard/summary?preset=previous_month')
            ->assertOk()
            ->assertJsonPath('data.period.from', '2026-08-01')
            ->assertJsonPath('data.period.to', '2026-08-31')
            ->assertJsonPath('data.current_total_balance.amount_centavos', 300_000)
            ->assertJsonPath('data.realized_income.amount_centavos', 0)
            ->assertJsonPath('data.realized_expenses.amount_centavos', 50_000);
    }

    #[Test]
    public function an_effective_transaction_re_dated_into_the_future_counts_in_its_stored_period(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user, ['current_balance_centavos' => 10_000]);

        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 40_000,
            'transaction_date' => '2026-10-02',
            'status' => TransactionStatus::Effective,
        ]);

        $this->getJson('/api/v1/financial-dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.realized_income.amount_centavos', 0);

        $this->getJson('/api/v1/financial-dashboard/summary?preset=custom&from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.period.preset', 'custom')
            ->assertJsonPath('data.realized_income.amount_centavos', 40_000)
            ->assertJsonPath('data.financial_result.amount_centavos', 40_000);
    }
}
