<?php

declare(strict_types=1);

namespace App\Data\CreditCards;

use App\Enums\CreditCards\CreditCardStatementStatus;
use App\Models\CreditCardInstallment;
use App\Models\CreditCardPurchase;
use App\Models\CreditCardStatement;
use App\Services\CreditCards\BillingCycleCalculator;
use Carbon\CarbonImmutable;

final class PurchaseResponseData
{
    /** @return array<string, mixed> */
    public static function from(CreditCardPurchase $purchase, bool $isDirectlyEditable): array
    {
        $purchase->loadMissing(['creditCard', 'category', 'installments.statement', 'creditEvents.applications']);

        return [
            'id' => $purchase->id,
            'card' => CreditCardResponseData::identity($purchase->creditCard),
            'category' => [
                'id' => $purchase->category->id,
                'name' => $purchase->category->name,
                'classification' => $purchase->category->classification->value,
                'status' => $purchase->category->status->value,
            ],
            'description' => $purchase->description,
            'notes' => $purchase->notes,
            'purchase_date' => $purchase->purchase_date->toDateString(),
            'total_amount' => CreditCardResponseData::money($purchase->total_amount_centavos),
            'installment_count' => $purchase->installment_count,
            'installments' => $purchase->installments
                ->map(fn (CreditCardInstallment $installment) => self::installment($installment, $purchase))
                ->all(),
            'credit_events' => $purchase->creditEvents
                ->map(fn ($event) => CreditEventResponseData::from($event))
                ->all(),
            'is_directly_editable' => $isDirectlyEditable,
        ];
    }

    /** @return array<string, mixed> */
    public static function installment(CreditCardInstallment $installment, CreditCardPurchase $purchase): array
    {
        $statement = $installment->statement;
        $businessDate = app(BillingCycleCalculator::class)->businessToday();

        return [
            'id' => $installment->id,
            'description' => $purchase->description,
            'purchase_date' => $purchase->purchase_date->toDateString(),
            'sequence' => $installment->sequence,
            'total_count' => $purchase->installment_count,
            'amount' => CreditCardResponseData::money($installment->amount_centavos),
            'credit_adjustment' => CreditCardResponseData::money($installment->credit_adjustment_centavos),
            'recognized_amount' => CreditCardResponseData::money($installment->netAmountCentavos()),
            'recognition_date' => $installment->recognition_date?->toDateString() ?? $statement?->closing_date?->toDateString(),
            'recognition_status' => self::recognitionStatus($statement, $businessDate),
            'statement' => $statement instanceof CreditCardStatement
                ? StatementResponseData::summary($statement, $purchase->creditCard, StatementResponseData::isCurrentCycle($purchase->creditCard, $statement, $businessDate), $businessDate)
                : null,
        ];
    }

    private static function recognitionStatus(?CreditCardStatement $statement, CarbonImmutable $businessDate): string
    {
        if (! $statement instanceof CreditCardStatement) {
            return 'pending';
        }

        return $statement->status === CreditCardStatementStatus::Open ? 'pending' : 'effective';
    }

    public static function isDirectlyEditable(CreditCardPurchase $purchase): bool
    {
        $businessDate = app(BillingCycleCalculator::class)->businessToday();

        return $purchase->installments()
            ->with('statement')
            ->get()
            ->every(fn (CreditCardInstallment $installment) => $installment->statement instanceof CreditCardStatement
                && app(BillingCycleCalculator::class)->isOpenOn($businessDate, $installment->statement->closing_date));
    }
}
