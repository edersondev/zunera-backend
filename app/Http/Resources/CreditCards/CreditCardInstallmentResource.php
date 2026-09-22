<?php

declare(strict_types=1);

namespace App\Http\Resources\CreditCards;

use App\Data\CreditCards\PurchaseResponseData;
use App\Models\CreditCardInstallment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditCardInstallment */
final class CreditCardInstallmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return PurchaseResponseData::installment($this->resource, $this->resource->purchase);
    }
}
