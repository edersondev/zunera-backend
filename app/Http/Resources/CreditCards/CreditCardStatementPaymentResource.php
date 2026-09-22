<?php

declare(strict_types=1);

namespace App\Http\Resources\CreditCards;

use App\Data\CreditCards\PaymentResponseData;
use App\Models\CreditCardStatementPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditCardStatementPayment */
final class CreditCardStatementPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return PaymentResponseData::from($this->resource);
    }
}
