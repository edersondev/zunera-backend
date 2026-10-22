<?php

declare(strict_types=1);

namespace App\Http\Resources\CreditCards;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CreditCardStatementOptionsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'business_date' => $this->resource['business_date'],
            'date_derived_closing_date' => $this->resource['date_derived_closing_date'],
            'requires_statement_choice' => $this->resource['requires_statement_choice'],
            'options' => CreditCardStatementOptionResource::collection($this->resource['options'])->resolve(),
            'next_from_month' => $this->resource['next_from_month'],
        ];
    }
}
