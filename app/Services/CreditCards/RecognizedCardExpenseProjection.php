<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Models\CreditCardCreditEvent;
use App\Models\CreditCardInstallment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Purchase-sequence recognition of card credit events. Settlement applications
 * may target a different statement after payment; they do not decide spending.
 * Each accepted event centavo instead reduces its source purchase installments
 * in original sequence, regardless of payment state.
 */
final class RecognizedCardExpenseProjection
{
    public function __construct(private readonly InstallmentAllocator $allocator) {}

    /**
     * One row per installment. Both SQLite and MySQL support the window sum;
     * all allocation remains in integer centavos. Date bounds are inclusive.
     */
    public function installments(int $userId, string $from, string $to, bool $effective = true): Builder
    {
        $sequenced = DB::table('credit_card_installments as installment')
            ->where('installment.user_id', $userId)
            ->select([
                'installment.id',
                'installment.credit_card_purchase_id',
                'installment.credit_card_statement_id',
                'installment.sequence',
                'installment.amount_centavos',
            ])
            ->selectRaw('SUM(installment.amount_centavos) OVER (PARTITION BY installment.credit_card_purchase_id ORDER BY installment.sequence, installment.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as purchase_prefix_centavos');

        $credits = DB::table('credit_card_credit_events')
            ->where('user_id', $userId)
            ->groupBy('credit_card_purchase_id')
            ->select('credit_card_purchase_id')
            ->selectRaw('SUM(amount_centavos) as total_credit_centavos');

        $credit = 'COALESCE(purchase_credits.total_credit_centavos, 0)';
        $before = '(sequenced.purchase_prefix_centavos - sequenced.amount_centavos)';
        $allocated = "CASE WHEN {$credit} <= {$before} THEN 0 WHEN {$credit} >= sequenced.purchase_prefix_centavos THEN sequenced.amount_centavos ELSE {$credit} - {$before} END";

        $query = DB::query()
            ->fromSub($sequenced, 'sequenced')
            ->join('credit_card_statements as statement', 'statement.id', '=', 'sequenced.credit_card_statement_id')
            ->join('credit_card_purchases as purchase', 'purchase.id', '=', 'sequenced.credit_card_purchase_id')
            ->leftJoinSub($credits, 'purchase_credits', 'purchase_credits.credit_card_purchase_id', '=', 'sequenced.credit_card_purchase_id')
            ->where('statement.user_id', $userId)
            ->where('purchase.user_id', $userId)
            ->whereDate('statement.closing_date', '>=', $from)
            ->whereDate('statement.closing_date', '<=', $to)
            ->selectRaw('sequenced.id as installment_id, sequenced.credit_card_purchase_id as purchase_id, sequenced.credit_card_statement_id as statement_id, sequenced.sequence, sequenced.amount_centavos, purchase.category_id, statement.closing_date')
            ->selectRaw("{$allocated} as recognized_adjustment_centavos")
            ->selectRaw("(sequenced.amount_centavos - {$allocated}) as recognized_amount_centavos");

        $businessToday = app(BillingCycleCalculator::class)->businessToday()->toDateString();

        return $effective
            ? $query->whereDate('statement.closing_date', '<', $businessToday)
            : $query->whereDate('statement.closing_date', '>=', $businessToday);
    }

    /**
     * @param  list<int>  $installmentIds
     * @return array<int, list<array{id: int, reason: string, recognized_amount_centavos: int}>>
     */
    public function creditEventsForInstallments(int $userId, array $installmentIds): array
    {
        if ($installmentIds === []) {
            return [];
        }

        $purchaseIds = CreditCardInstallment::query()
            ->where('user_id', $userId)
            ->whereIn('id', $installmentIds)
            ->distinct()
            ->pluck('credit_card_purchase_id')
            ->all();

        $installments = CreditCardInstallment::query()
            ->where('user_id', $userId)
            ->whereIn('credit_card_purchase_id', $purchaseIds)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->groupBy('credit_card_purchase_id');
        $events = CreditCardCreditEvent::query()
            ->where('user_id', $userId)
            ->whereIn('credit_card_purchase_id', $purchaseIds)
            ->orderBy('id')
            ->get()
            ->groupBy('credit_card_purchase_id');

        $selected = array_fill_keys($installmentIds, true);
        $result = [];
        foreach ($purchaseIds as $purchaseId) {
            $slots = $installments->get($purchaseId, collect())
                ->map(static fn (CreditCardInstallment $installment): array => [
                    'id' => (int) $installment->id,
                    'amount_centavos' => $installment->amount_centavos,
                    'credited_centavos' => 0,
                ])->all();
            foreach ($events->get($purchaseId, collect()) as $event) {
                foreach ($this->allocator->allocateCredit($slots, $event->amount_centavos) as $allocation) {
                    foreach ($slots as &$slot) {
                        if ($slot['id'] === $allocation['id']) {
                            $slot['credited_centavos'] += $allocation['amount_centavos'];
                            break;
                        }
                    }
                    unset($slot);

                    if (isset($selected[$allocation['id']])) {
                        $result[$allocation['id']][] = [
                            'id' => (int) $event->id,
                            'reason' => $event->reason->value,
                            'recognized_amount_centavos' => $allocation['amount_centavos'],
                        ];
                    }
                }
            }
        }

        return $result;
    }
}
