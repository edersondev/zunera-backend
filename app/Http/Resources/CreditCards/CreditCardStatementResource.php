<?php

declare(strict_types=1);

namespace App\Http\Resources\CreditCards;

use App\Data\CreditCards\StatementResponseData;
use App\Models\CreditCardStatement;
use App\Services\CreditCards\BillingCycleCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditCardStatement */
final class CreditCardStatementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $businessDate = app(BillingCycleCalculator::class)->businessToday();
        $card = $this->resource->creditCard;

        return StatementResponseData::summary(
            $this->resource,
            $card,
            StatementResponseData::isCurrentCycle($card, $this->resource, $businessDate),
            $businessDate,
        );
    }
}
