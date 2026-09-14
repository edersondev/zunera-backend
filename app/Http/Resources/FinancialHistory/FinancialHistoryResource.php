<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialHistory;

use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Discriminated read projection shared by the mixed financial-history endpoint.
 * Transfers use movement_kind = transfer, carry both account sides, never carry a
 * category, and never participate in income/expense signage.
 *
 * @mixin Transaction|Transfer
 */
final class FinancialHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $movement = $this->resource;

        return $movement instanceof Transfer
            ? $this->transferEntry($movement)
            : $this->transactionEntry($movement);
    }

    /** @return array<string, mixed> */
    private function transactionEntry(Transaction $transaction): array
    {
        return [
            'movement_kind' => $transaction->type->value,
            'id' => $transaction->id,
            'amount_centavos' => $transaction->amount_centavos,
            'currency_code' => $transaction->currency_code,
            'movement_date' => $transaction->transaction_date->toDateString(),
            'status' => $transaction->status->value,
            'description' => $transaction->description,
            'notes' => $transaction->notes,
            'financial_account' => $this->accountSummary($transaction->financialAccount),
            'category' => [
                'id' => $transaction->category?->id,
                'name' => $transaction->category?->name,
                'classification' => $transaction->category?->classification->value,
                'status' => $transaction->category?->status->value,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function transferEntry(Transfer $transfer): array
    {
        return [
            'movement_kind' => 'transfer',
            'id' => $transfer->id,
            'amount_centavos' => $transfer->amount_centavos,
            'currency_code' => $transfer->currency_code,
            'movement_date' => $transfer->transfer_date->toDateString(),
            'status' => $transfer->status->value,
            'description' => $transfer->description,
            'notes' => $transfer->notes,
            'source_financial_account' => $this->accountSummary($transfer->sourceAccount),
            'destination_financial_account' => $this->accountSummary($transfer->destinationAccount),
            'category' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function accountSummary(?FinancialAccount $account): array
    {
        return [
            'id' => $account?->id,
            'name' => $account?->name,
            'status' => $account?->status->value,
        ];
    }
}
