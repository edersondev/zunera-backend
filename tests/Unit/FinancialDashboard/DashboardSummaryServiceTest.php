<?php

declare(strict_types=1);

namespace Tests\Unit\FinancialDashboard;

use App\Data\FinancialDashboard\DashboardPeriodData;
use App\Enums\Transactions\TransactionStatus;
use App\Models\User;
use App\Services\FinancialDashboard\DashboardSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialDashboard\FinancialDashboardFixtures;
use Tests\TestCase;

final class DashboardSummaryServiceTest extends TestCase
{
    use FinancialDashboardFixtures;
    use RefreshDatabase;

    #[Test]
    public function it_keeps_exact_centavo_income_expense_and_result_values(): void
    {
        $user = $this->dashboardAccountWithMovements();
        $period = DashboardPeriodData::custom('2026-09-01', '2026-09-30');

        $summary = app(DashboardSummaryService::class)->summary($user, $period);

        self::assertSame(123_456_789, $summary['realized_income_centavos']);
        self::assertSame(987_654, $summary['realized_expenses_centavos']);
        self::assertSame(122_469_135, $summary['financial_result_centavos']);
        self::assertSame('BRL', $summary['currency_code']);
        self::assertSame('2026-09-01', $summary['period']->from);
    }

    #[Test]
    public function it_ignores_pending_removed_out_of_period_and_transfer_records(): void
    {
        $user = $this->dashboardAccountWithMovements();
        $period = DashboardPeriodData::custom('2026-09-01', '2026-09-30');

        $summary = app(DashboardSummaryService::class)->summary($user, $period);

        self::assertSame(123_456_789, $summary['realized_income_centavos']);
        self::assertSame(987_654, $summary['realized_expenses_centavos']);
    }

    #[Test]
    public function it_derives_the_current_balance_from_active_owned_accounts_only(): void
    {
        $user = $this->dashboardAccountWithMovements();
        $period = DashboardPeriodData::custom('2026-09-01', '2026-09-30');

        $summary = app(DashboardSummaryService::class)->summary($user, $period);

        self::assertSame(1_500_000, $summary['current_total_balance_centavos']);
    }

    private function dashboardAccountWithMovements(): User
    {
        $user = User::factory()->create();
        $active = $this->dashboardAccount($user, ['current_balance_centavos' => 1_500_000]);
        $archived = $this->dashboardArchivedAccount($user, ['current_balance_centavos' => 700_000]);

        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $active->id,
            'amount_centavos' => 123_456_789,
            'transaction_date' => '2026-09-10',
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $active->id,
            'amount_centavos' => 987_654,
            'transaction_date' => '2026-09-11',
        ]);
        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $active->id,
            'amount_centavos' => 555_555,
            'transaction_date' => '2026-09-12',
            'status' => TransactionStatus::Pending,
        ]);
        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $active->id,
            'amount_centavos' => 444_444,
            'transaction_date' => '2026-09-12',
            'removed_at' => now(),
        ]);
        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $archived->id,
            'amount_centavos' => 333_333,
            'transaction_date' => '2026-07-12',
        ]);
        $this->dashboardTransfer($user, [
            'source_financial_account_id' => $active->id,
            'destination_financial_account_id' => $archived->id,
            'amount_centavos' => 222_222,
            'transfer_date' => '2026-09-13',
        ]);

        return $user;
    }
}
