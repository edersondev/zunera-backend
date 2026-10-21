<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Enums\CreditCards\CreditCardPaymentStatus;
use App\Enums\CreditCards\CreditCardStatementStatus;
use App\Models\CreditCard;
use App\Models\CreditCardPaymentSurplusApplication;
use App\Models\CreditCardStatement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single owner of derived card money and payment-surplus allocations.
 */
final class CreditCardObligationReconciler
{
    public function __construct(private readonly BillingCycleCalculator $cycles) {}

    public function usedCreditCentavos(CreditCard $card): int
    {
        return (int) CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->get()
            ->sum(fn (CreditCardStatement $statement): int => $statement->outstandingCentavos());
    }

    public function cardCreditCentavos(CreditCard $card): int
    {
        $events = (int) DB::table('credit_card_credit_events')
            ->where('credit_card_id', $card->id)
            ->sum('amount_centavos');

        $applied = (int) DB::table('credit_card_credit_applications')
            ->where('credit_card_id', $card->id)
            ->whereNull('superseded_at')
            ->sum('amount_centavos');

        $surplus = (int) CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->get()
            ->sum(fn (CreditCardStatement $statement): int => max(0, $statement->paid_centavos - $statement->netAmountCentavos()));
        $surplusApplied = (int) CreditCardPaymentSurplusApplication::query()
            ->where('credit_card_id', $card->id)
            ->active()
            ->sum('amount_centavos');

        return max(0, $events - $applied) + max(0, $surplus - $surplusApplied);
    }

    public function availableCreditCentavos(CreditCard $card): int
    {
        return $card->credit_limit_centavos - $this->usedCreditCentavos($card) + $this->cardCreditCentavos($card);
    }

    /** @return array{credit_limit_centavos: int, used_credit_centavos: int, card_credit_centavos: int, available_credit_centavos: int, is_over_limit: bool} */
    public function summary(CreditCard $card): array
    {
        $used = $this->usedCreditCentavos($card);
        $credit = $this->cardCreditCentavos($card);
        $available = $card->credit_limit_centavos - $used + $credit;

        return [
            'credit_limit_centavos' => $card->credit_limit_centavos,
            'used_credit_centavos' => $used,
            'card_credit_centavos' => $credit,
            'available_credit_centavos' => $available,
            'is_over_limit' => $available < 0,
        ];
    }

    public function outstandingCentavos(CreditCardStatement $statement): int
    {
        return $statement->outstandingCentavos();
    }

    /** Oldest unpaid statements first, by due date then id. */
    public function oldestUnpaidStatements(CreditCard $card, int $excludeStatementId = 0): Collection
    {
        return CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->when($excludeStatementId > 0, fn ($query) => $query->whereKeyNot($excludeStatementId))
            ->whereRaw('(original_amount_centavos - credit_adjustment_centavos - paid_centavos - card_credit_applied_centavos) > 0')
            ->orderBy('due_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function statusFor(CreditCardStatement $statement, CarbonImmutable $businessDate): CreditCardStatementStatus
    {
        if ($this->cycles->isOpenOn($businessDate, $statement->closing_date)) {
            return CreditCardStatementStatus::Open;
        }

        if ($statement->outstandingCentavos() === 0) {
            return CreditCardStatementStatus::Paid;
        }

        if ($businessDate->toDateString() > $statement->due_date->toDateString()) {
            return CreditCardStatementStatus::Overdue;
        }

        return $statement->paid_centavos + $statement->card_credit_applied_centavos > 0
            ? CreditCardStatementStatus::PartiallyPaid
            : CreditCardStatementStatus::Closed;
    }

    public function syncStatement(CreditCardStatement $statement, CarbonImmutable $businessDate): CreditCardStatement
    {
        $original = (int) DB::table('credit_card_installments')
            ->where('credit_card_statement_id', $statement->id)
            ->sum('amount_centavos');

        $creditAdjustments = (int) DB::table('credit_card_installments')
            ->where('credit_card_statement_id', $statement->id)
            ->sum('credit_adjustment_centavos');

        $paid = (int) DB::table('credit_card_statement_payments')
            ->where('credit_card_statement_id', $statement->id)
            ->where('status', CreditCardPaymentStatus::Effective->value)
            ->whereNull('removed_at')
            ->sum('amount_centavos');

        $cardCreditApplied = (int) DB::table('credit_card_credit_applications')
            ->where('credit_card_statement_id', $statement->id)
            ->where('kind', 'statement')
            ->whereNull('superseded_at')
            ->sum('amount_centavos');
        $cardCreditApplied += (int) CreditCardPaymentSurplusApplication::query()
            ->where('destination_statement_id', $statement->id)
            ->active()
            ->sum('amount_centavos');

        $statement->forceFill([
            'original_amount_centavos' => $original,
            'credit_adjustment_centavos' => $creditAdjustments,
            'paid_centavos' => $paid,
            'card_credit_applied_centavos' => $cardCreditApplied,
        ]);

        $status = $this->statusFor($statement, $businessDate);
        $statement->status = $status;
        if ($status !== CreditCardStatementStatus::Open && $statement->finalized_at === null) {
            $statement->finalized_at = now();
        }
        $statement->save();

        return $statement;
    }

    /** Marks every affected statement as realized on its closing date. */
    public function refreshCardStatements(CreditCard $card, CarbonImmutable $businessDate): void
    {
        $statements = CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->orderBy('closing_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $statements->each(fn (CreditCardStatement $statement) => $this->syncStatement($statement, $businessDate));
        $this->rebuildPaymentSurplus($card, $statements, $businessDate);
        $statements->each(fn (CreditCardStatement $statement) => $this->syncStatement($statement, $businessDate));
    }

    /** @param Collection<int, CreditCardStatement> $statements */
    private function rebuildPaymentSurplus(CreditCard $card, Collection $statements, CarbonImmutable $businessDate): void
    {
        $eventCredits = DB::table('credit_card_credit_applications')
            ->where('credit_card_id', $card->id)
            ->where('kind', 'statement')
            ->whereNull('superseded_at')
            ->groupBy('credit_card_statement_id')
            ->selectRaw('credit_card_statement_id, SUM(amount_centavos) as total')
            ->pluck('total', 'credit_card_statement_id');

        $sources = $statements
            ->filter(fn (CreditCardStatement $statement): bool => $statement->paid_centavos > $statement->netAmountCentavos())
            ->sortBy([['due_date', 'asc'], ['id', 'asc']]);
        $owed = [];
        foreach ($statements as $statement) {
            $owed[$statement->id] = max(0,
                $statement->netAmountCentavos()
                - $statement->paid_centavos
                - (int) ($eventCredits[$statement->id] ?? 0),
            );
        }

        $targets = $statements->sortBy([['due_date', 'asc'], ['id', 'asc']]);
        $desired = [];
        foreach ($sources as $source) {
            $remaining = max(0, $source->paid_centavos - $source->netAmountCentavos());
            foreach ($targets as $target) {
                if ($remaining === 0) {
                    break;
                }
                if ($target->id === $source->id || $owed[$target->id] === 0) {
                    continue;
                }
                $amount = min($remaining, $owed[$target->id]);
                $desired[$source->id.':'.$target->id] = $amount;
                $remaining -= $amount;
                $owed[$target->id] -= $amount;
            }
        }

        $active = CreditCardPaymentSurplusApplication::query()
            ->where('credit_card_id', $card->id)
            ->active()
            ->lockForUpdate()
            ->get();
        $current = [];
        foreach ($active as $application) {
            $key = $application->source_statement_id.':'.$application->destination_statement_id;
            $current[$key] = ($current[$key] ?? 0) + $application->amount_centavos;
        }
        ksort($current);
        ksort($desired);
        if ($current === $desired) {
            return;
        }

        CreditCardPaymentSurplusApplication::query()
            ->where('credit_card_id', $card->id)
            ->active()
            ->update(['superseded_at' => now()]);
        foreach ($desired as $key => $amount) {
            [$sourceId, $targetId] = array_map('intval', explode(':', $key));
            CreditCardPaymentSurplusApplication::query()->create([
                'user_id' => $card->user_id,
                'credit_card_id' => $card->id,
                'source_statement_id' => $sourceId,
                'destination_statement_id' => $targetId,
                'amount_centavos' => $amount,
                'applied_at' => $businessDate->toDateString(),
            ]);
        }
    }

    public function currentStatement(CreditCard $card, CarbonImmutable $businessDate): ?CreditCardStatement
    {
        $cycle = $this->cycles->cycleForDate($businessDate, $card->closing_day, $card->due_day);

        return CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->where('closing_date', $cycle->closingDate)
            ->first();
    }
}
