<?php

declare(strict_types=1);

namespace App\Http\Resources\CreditCards;

use App\Data\CreditCards\CreditCardResponseData;
use App\Models\CreditCard;
use App\Services\CreditCards\BillingCycleCalculator;
use App\Services\CreditCards\CreditCardObligationReconciler;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditCard */
final class CreditCardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return CreditCardResponseData::card(
            $this->resource,
            app(CreditCardObligationReconciler::class),
            app(BillingCycleCalculator::class)->businessToday(),
        );
    }
}
