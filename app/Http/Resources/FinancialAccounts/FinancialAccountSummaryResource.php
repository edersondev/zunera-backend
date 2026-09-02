<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FinancialAccountSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'active_account_count' => $this->resource['active_account_count'],
            'active_combined_balance_centavos' => $this->resource['active_combined_balance_centavos'],
            'currency_code' => $this->resource['currency_code'],
        ];
    }
}
