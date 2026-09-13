<?php

declare(strict_types=1);

namespace App\Http\Resources\Transfers;

use App\Models\FinancialAccount;
use App\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transfer */
final class TransferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Transfer $transfer */
        $transfer = $this->resource;

        return [
            'id' => $transfer->id,
            'source_financial_account' => $this->accountSummary($transfer->sourceAccount),
            'destination_financial_account' => $this->accountSummary($transfer->destinationAccount),
            'amount_centavos' => $transfer->amount_centavos,
            'currency_code' => $transfer->currency_code,
            'transfer_date' => $transfer->transfer_date->toDateString(),
            'status' => $transfer->status->value,
            'description' => $transfer->description,
            'notes' => $transfer->notes,
            'removed_at' => $transfer->removed_at?->toIso8601String(),
            'created_at' => $transfer->created_at?->toIso8601String(),
            'updated_at' => $transfer->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function accountSummary(?FinancialAccount $account): array
    {
        return [
            'id' => $account?->id,
            'name' => $account?->name,
            'status' => $account?->status->value,
            'color' => $account?->color,
            'icon' => $account?->icon,
        ];
    }
}
