<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Enums\CreditCards\CreditCardPaymentStatus;
use App\Enums\CreditCards\CreditCardStatementStatus;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single owner of derived card money: used credit is the remaining purchase
 * principal after effective statement payments and applied credit events, card
 * credit is unapplied credit-event value, and available credit is the stated
 * limit minus used credit plus card credit.
 */
final class CreditCardObligationReconciler
{
    public function __construct(private readonly BillingCycleCalculator $cycles) {}

    public function usedCreditCentavos(CreditCard $card): int
    {
        $installmentNet = (int) DB::table('credit_card_installments')
            ->where('credit_card_id', $card->id)
            ->selectRaw('COALESCE(SUM(amount_centavos - credit_adjustment_centavos), 0) as total')
            ->value('total');

        $settled = (int) DB::table('credit_card_statements')
            ->where('credit_card_id', $card->id)
            ->selectRaw('COALESCE(SUM(paid_centavos + card_credit_applied_centavos), 0) as total')
            ->value('total');

        return max(0, $installmentNet - $settled);
    }

    public function cardCreditCentavos(CreditCard $card): int
    {
        $events = (int) DB::table('credit_card_credit_events')
            ->where('credit_card_id', $card->id)
            ->sum('amount_centavos');

        $applied = (int) DB::table('credit_card_credit_applications')
            ->where('credit_card_id', $card->id)
            ->sum('amount_centavos');

        return max(0, $events - $applied);
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
        CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->orderBy('closing_date')
            ->lockForUpdate()
            ->get()
            ->each(fn (CreditCardStatement $statement) => $this->syncStatement($statement, $businessDate));
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
