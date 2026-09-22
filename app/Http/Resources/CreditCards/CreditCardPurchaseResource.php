<?php

declare(strict_types=1);

namespace App\Http\Resources\CreditCards;

use App\Data\CreditCards\PurchaseResponseData;
use App\Models\CreditCardPurchase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditCardPurchase */
final class CreditCardPurchaseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return PurchaseResponseData::from($this->resource, PurchaseResponseData::isDirectlyEditable($this->resource));
    }
}
