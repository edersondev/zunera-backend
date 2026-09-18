<?php

declare(strict_types=1);

namespace App\Services\FinancialDashboard;

use App\Data\FinancialDashboard\DashboardPeriodData;
use App\Enums\FinancialAccounts\AccountStatus;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Owner-scoped realized summary. Only effective, non-removed income/expense
 * transactions dated inside the inclusive reporting period count; transfers and
 * planned records never reach realized income, expenses, or result.
 */
final class DashboardSummaryService
{
    /** @return array<string, mixed> */
    public function summary(User $user, DashboardPeriodData $period): array
    {
        $row = DB::table('transactions')
            ->where('user_id', $user->id)
            ->whereNull('removed_at')
            ->where('status', 'effective')
            ->whereDate('transaction_date', '>=', $period->from)
            ->whereDate('transaction_date', '<=', $period->to)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount_centavos ELSE 0 END), 0) as income_centavos")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount_centavos ELSE 0 END), 0) as expense_centavos")
            ->first();

        $income = (int) ($row->income_centavos ?? 0);
        $expenses = (int) ($row->expense_centavos ?? 0);

        return [
            'period' => $period,
            'current_total_balance_centavos' => $this->currentTotalBalance($user),
            'realized_income_centavos' => $income,
            'realized_expenses_centavos' => $expenses,
            'financial_result_centavos' => $income - $expenses,
            'currency_code' => 'BRL',
        ];
    }

    /** Current position uses active owned accounts only, independent of the reporting period. */
    private function currentTotalBalance(User $user): int
    {
        return (int) FinancialAccount::query()
            ->where('user_id', $user->id)
            ->where('status', AccountStatus::Active)
            ->sum('current_balance_centavos');
    }
}
