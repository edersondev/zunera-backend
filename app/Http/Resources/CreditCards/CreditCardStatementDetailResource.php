<?php

declare(strict_types=1);

namespace App\Http\Resources\CreditCards;

use App\Data\CreditCards\CreditCardResponseData;
use App\Data\CreditCards\CreditEventResponseData;
use App\Data\CreditCards\PaymentResponseData;
use App\Data\CreditCards\PurchaseResponseData;
use App\Data\CreditCards\StatementResponseData;
use App\Models\CreditCardInstallment;
use App\Models\CreditCardStatement;
use App\Services\CreditCards\BillingCycleCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditCardStatement */
final class CreditCardStatementDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $businessDate = app(BillingCycleCalculator::class)->businessToday();
        $card = $this->resource->creditCard;

        return [
            ...StatementResponseData::summary(
                $this->resource,
                $card,
                StatementResponseData::isCurrentCycle($card, $this->resource, $businessDate),
                $businessDate,
            ),
            'installments' => $this->resource->installments
                ->map(fn (CreditCardInstallment $installment) => [
                    ...PurchaseResponseData::installment($installment, $installment->purchase),
                    'purchase_id' => $installment->purchase->id,
                    'category' => [
                        'id' => $installment->purchase->category->id,
                        'name' => $installment->purchase->category->name,
                        'icon' => $installment->purchase->category->icon,
                        'color' => $installment->purchase->category->color,
                    ],
                    'purchase_total_amount' => CreditCardResponseData::money($installment->purchase->total_amount_centavos),
                    'recurrence_source' => PurchaseResponseData::recurrenceSource($installment->purchase),
                    'is_directly_editable' => PurchaseResponseData::isDirectlyEditable($installment->purchase),
                ])
                ->all(),
            'payments' => $this->resource->payments
                ->map(fn ($payment) => PaymentResponseData::from($payment))
                ->all(),
            'credit_events' => $this->resource->creditApplications
                ->map(fn ($application) => CreditEventResponseData::from($application->creditEvent))
                ->unique('id')
                ->values()
                ->all(),
        ];
    }
}
