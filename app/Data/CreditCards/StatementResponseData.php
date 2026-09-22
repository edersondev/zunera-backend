<?php

declare(strict_types=1);

namespace App\Data\CreditCards;

use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Services\CreditCards\BillingCycleCalculator;
use Carbon\CarbonImmutable;

final class StatementResponseData
{
    /** @return array<string, mixed> */
    public static function summary(
        CreditCardStatement $statement,
        CreditCard $card,
        bool $isCurrent,
        CarbonImmutable $businessDate,
    ): array {
        return [
            'id' => $statement->id,
            'card' => CreditCardResponseData::identity($card),
            'period_from' => $statement->period_from->toDateString(),
            'period_to' => $statement->period_to->toDateString(),
            'closing_date' => $statement->closing_date->toDateString(),
            'due_date' => $statement->due_date->toDateString(),
            'original_amount' => CreditCardResponseData::money($statement->original_amount_centavos),
            'credit_adjustments' => CreditCardResponseData::money($statement->credit_adjustment_centavos),
            'net_amount' => CreditCardResponseData::money($statement->netAmountCentavos()),
            'paid_amount' => CreditCardResponseData::money($statement->paid_centavos),
            'card_credit_applied' => CreditCardResponseData::money($statement->card_credit_applied_centavos),
            'outstanding_amount' => CreditCardResponseData::money($statement->outstandingCentavos()),
            'status' => $statement->status->value,
            'is_current' => $isCurrent,
        ];
    }

    /** Active cards always expose a current statement, even with no activity. */
    public static function synthesizedCurrent(CreditCard $card, CarbonImmutable $businessDate, bool $isCurrent): array
    {
        $cycle = app(BillingCycleCalculator::class)->cycleForDate($businessDate, $card->closing_day, $card->due_day);

        return [
            'id' => null,
            'card' => CreditCardResponseData::identity($card),
            'period_from' => $cycle->periodFrom,
            'period_to' => $cycle->periodTo,
            'closing_date' => $cycle->closingDate,
            'due_date' => $cycle->dueDate,
            'original_amount' => CreditCardResponseData::money(0),
            'credit_adjustments' => CreditCardResponseData::money(0),
            'net_amount' => CreditCardResponseData::money(0),
            'paid_amount' => CreditCardResponseData::money(0),
            'card_credit_applied' => CreditCardResponseData::money(0),
            'outstanding_amount' => CreditCardResponseData::money(0),
            'status' => 'open',
            'is_current' => $isCurrent,
        ];
    }

    public static function isCurrentCycle(CreditCard $card, CreditCardStatement $statement, CarbonImmutable $businessDate): bool
    {
        if (! $card->isActive()) {
            return false;
        }

        $cycle = app(BillingCycleCalculator::class)->cycleForDate($businessDate, $card->closing_day, $card->due_day);

        return $statement->closing_date->toDateString() === $cycle->closingDate;
    }
}
