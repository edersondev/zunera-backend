<?php

declare(strict_types=1);

namespace App\Http\Resources\CreditCards;

use App\Data\CreditCards\CreditCardResponseData;
use App\Models\CreditCardCreditApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditCardCreditApplication */
final class CreditCardCreditApplicationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'kind' => $this->resource->kind->value,
            'installment_id' => $this->resource->credit_card_installment_id,
            'statement_id' => $this->resource->credit_card_statement_id,
            'amount' => CreditCardResponseData::money($this->resource->amount_centavos),
            'applied_at' => $this->resource->applied_at?->toDateString(),
        ];
    }
}
