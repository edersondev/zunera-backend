<?php

declare(strict_types=1);

namespace App\Http\Resources\RecurringTransactions;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transaction */
final class GeneratedOccurrenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Transaction $transaction */
        $transaction = $this->resource;

        return [
            'id' => $transaction->id,
            'scheduled_date' => $transaction->recurrence_scheduled_date?->toDateString(),
            'status' => $transaction->status->value,
            'removed_at' => $transaction->removed_at?->toIso8601String(),
        ];
    }
}
