<?php

declare(strict_types=1);

namespace App\Data\Transactions;

use App\Models\Transaction;

final class TransactionResponseData
{
    /** @return array<string, mixed> */
    public static function from(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'status' => $transaction->status->value,
            'description' => $transaction->description,
            'notes' => $transaction->notes,
            'amount_centavos' => $transaction->amount_centavos,
            'currency_code' => $transaction->currency_code,
            'transaction_date' => $transaction->transaction_date->toDateString(),
            'removed_at' => $transaction->removed_at?->toIso8601String(),
            'financial_account' => [
                'id' => $transaction->financialAccount->id,
                'name' => $transaction->financialAccount->name,
                'status' => $transaction->financialAccount->status->value,
                'color' => $transaction->financialAccount->color,
                'icon' => $transaction->financialAccount->icon,
            ],
            'category' => [
                'id' => $transaction->category->id,
                'name' => $transaction->category->name,
                'classification' => $transaction->category->classification->value,
                'origin' => $transaction->category->origin->value,
                'status' => $transaction->category->status->value,
                'color' => $transaction->category->color,
                'icon' => $transaction->category->icon,
            ],
            'created_at' => $transaction->created_at->toIso8601String(),
            'updated_at' => $transaction->updated_at->toIso8601String(),
        ];
    }
}
