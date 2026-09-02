<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialAccounts;

use App\Models\FinancialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FinancialAccount */
final class FinancialAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'account_type' => $this->account_type->value,
            'status' => $this->status->value,
            'institution_name' => $this->institution_name,
            'color' => $this->color,
            'icon' => $this->icon,
            'initial_balance_centavos' => $this->initial_balance_centavos,
            'current_balance_centavos' => $this->current_balance_centavos,
            'currency_code' => $this->currency_code,
            'has_financial_movements' => $this->has_financial_movements,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
