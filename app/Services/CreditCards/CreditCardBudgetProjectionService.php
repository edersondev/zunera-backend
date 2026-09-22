<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Card spending enters budgets once, in the calendar month of its statement
 * closing date: still-open statements are expected, closed ones are realized.
 * The net recognized amount (installment minus applied credit events) is used,
 * and statement payments never appear here because they are settlement only.
 */
final class CreditCardBudgetProjectionService
{
    public function __construct(private readonly BillingCycleCalculator $cycles) {}

    /** @return Collection<int, int> category id => realized centavos */
    public function realizedByCategory(User $user, string $from, string $to): Collection
    {
        return $this->byCategory($user, $from, $to, effective: true);
    }

    /** @return Collection<int, int> category id => expected centavos */
    public function expectedByCategory(User $user, string $from, string $to): Collection
    {
        return $this->byCategory($user, $from, $to, effective: false);
    }

    /** @return Collection<int, int> */
    private function byCategory(User $user, string $from, string $to, bool $effective): Collection
    {
        // Effectiveness is derived from the business date, not from a stored
        // status that may not have been refreshed since the last card read: an
        // installment closes with its statement and never reopens.
        $businessDate = $this->cycles->businessToday()->toDateString();

        $query = DB::table('credit_card_installments')
            ->join('credit_card_statements', 'credit_card_statements.id', '=', 'credit_card_installments.credit_card_statement_id')
            ->join('credit_card_purchases', 'credit_card_purchases.id', '=', 'credit_card_installments.credit_card_purchase_id')
            ->where('credit_card_installments.user_id', $user->id)
            ->whereDate('credit_card_statements.closing_date', '>=', $from)
            ->whereDate('credit_card_statements.closing_date', '<=', $to)
            ->groupBy('credit_card_purchases.category_id');

        if ($effective) {
            $query->whereDate('credit_card_statements.closing_date', '<', $businessDate);
        } else {
            $query->whereDate('credit_card_statements.closing_date', '>=', $businessDate);
        }

        return $query
            ->selectRaw('credit_card_purchases.category_id as category_id, SUM(credit_card_installments.amount_centavos - credit_card_installments.credit_adjustment_centavos) as total_centavos')
            ->pluck('total_centavos', 'category_id')
            ->map(static fn (mixed $total): int => max(0, (int) $total));
    }
}
