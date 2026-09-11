<?php

declare(strict_types=1);

namespace App\Http\Resources\Transactions;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transaction */
final class TransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'description' => $this->description,
            'notes' => $this->notes,
            'amount_centavos' => $this->amount_centavos,
            'currency_code' => $this->currency_code,
            'transaction_date' => $this->transaction_date->toDateString(),
            'removed_at' => $this->removed_at?->toIso8601String(),
            'financial_account' => [
                'id' => $this->financialAccount->id,
                'name' => $this->financialAccount->name,
                'status' => $this->financialAccount->status->value,
                'color' => $this->financialAccount->color,
                'icon' => $this->financialAccount->icon,
            ],
            'category' => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'classification' => $this->category->classification->value,
                'origin' => $this->category->origin->value,
                'status' => $this->category->status->value,
                'color' => $this->category->color,
                'icon' => $this->category->icon,
            ],
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
