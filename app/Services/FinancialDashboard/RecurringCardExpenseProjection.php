<?php

declare(strict_types=1);

namespace App\Services\FinancialDashboard;

use App\Models\User;
use App\Services\CreditCards\BillingCycleCalculator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Realized card spending uses the installment's closing date and net amount.
 * Rules and unconfirmed occurrences never enter these totals.
 */
final class RecurringCardExpenseProjection
{
    public function __construct(private readonly BillingCycleCalculator $cycles) {}

    public function total(User $user, string $from, string $to): int
    {
        return (int) $this->installments($user, $from, $to)
            ->selectRaw('COALESCE(SUM(credit_card_installments.amount_centavos - credit_card_installments.credit_adjustment_centavos), 0) as total_centavos')
            ->value('total_centavos');
    }

    /** @return Collection<int, object> */
    public function byCategory(User $user, string $from, string $to): Collection
    {
        return $this->installments($user, $from, $to)
            ->join('categories', 'categories.id', '=', 'credit_card_purchases.category_id')
            ->groupBy('categories.id', 'categories.name', 'categories.classification', 'categories.status')
            ->selectRaw('categories.id as category_id, categories.name as category_name, categories.classification as category_classification, categories.status as category_status')
            ->selectRaw('SUM(credit_card_installments.amount_centavos - credit_card_installments.credit_adjustment_centavos) as total_centavos')
            ->get();
    }

    /** @return Collection<int, object> */
    public function byDate(User $user, string $from, string $to): Collection
    {
        return $this->installments($user, $from, $to)
            ->groupBy('credit_card_statements.closing_date')
            ->selectRaw('credit_card_statements.closing_date as movement_date')
            ->selectRaw('SUM(credit_card_installments.amount_centavos - credit_card_installments.credit_adjustment_centavos) as expenses_centavos')
            ->get();
    }

    private function installments(User $user, string $from, string $to): Builder
    {
        return DB::table('credit_card_installments')
            ->join('credit_card_statements', 'credit_card_statements.id', '=', 'credit_card_installments.credit_card_statement_id')
            ->join('credit_card_purchases', 'credit_card_purchases.id', '=', 'credit_card_installments.credit_card_purchase_id')
            ->where('credit_card_installments.user_id', $user->id)
            ->whereDate('credit_card_statements.closing_date', '>=', $from)
            ->whereDate('credit_card_statements.closing_date', '<=', $to)
            ->whereDate('credit_card_statements.closing_date', '<', $this->cycles->businessToday()->toDateString());
    }
}
