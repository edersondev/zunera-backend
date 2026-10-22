<?php

declare(strict_types=1);

namespace App\Http\Resources\CreditCards;

use App\Models\CreditCardPurchaseMove;
use App\Services\CreditCards\BillingCycleCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditCardPurchaseMove */
final class CreditCardPurchaseMoveResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $businessDate = app(BillingCycleCalculator::class)->businessToday()->toDateString();

        return [
            'id' => $this->id,
            'mode' => $this->mode,
            'installment_id' => $this->credit_card_installment_id,
            'sequence' => $this->sequence,
            'source' => [
                'closing_date' => $this->source_closing_date->toDateString(),
                'period_from' => $this->source_period_from->toDateString(),
                'period_to' => $this->source_period_to->toDateString(),
                'due_date' => $this->source_due_date->toDateString(),
                'statement_id' => $this->source_statement_id,
                'closed_by_date' => $this->source_closing_date->toDateString() < $businessDate,
                'is_persisted' => true,
            ],
            'destination' => [
                'closing_date' => $this->destination_closing_date->toDateString(),
                'period_from' => $this->destination_period_from->toDateString(),
                'period_to' => $this->destination_period_to->toDateString(),
                'due_date' => $this->destination_due_date->toDateString(),
                'statement_id' => $this->destination_statement_id,
                'closed_by_date' => $this->destination_closing_date->toDateString() < $businessDate,
                'is_persisted' => true,
            ],
            'moved_at' => $this->moved_at->toIso8601String(),
        ];
    }
}
