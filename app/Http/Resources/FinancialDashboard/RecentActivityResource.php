<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialDashboard;

use App\Models\CreditCardPurchase;
use App\Models\Transaction;
use App\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Discriminated newest-first activity projection. Transfers carry both account
 * sides and never carry a category or recurrence source.
 *
 * @property list<array{kind: string, model: Transaction|Transfer|CreditCardPurchase}> $resource
 */
final class RecentActivityResource extends JsonResource
{
    /** @return list<array<string, mixed>> */
    public function toArray(Request $request): array
    {
        return array_map(
            fn (array $entry): array => match ($entry['kind']) {
                'transfer' => $this->transferEntry($entry['model']),
                'card_purchase' => $this->cardPurchaseEntry($entry['model']),
                default => $this->transactionEntry($entry['model']),
            },
            $this->resource,
        );
    }

    /** @return array<string, mixed> */
    private function cardPurchaseEntry(CreditCardPurchase $purchase): array
    {
        $occurrence = $purchase->recurringCardOccurrence;

        return [
            'movement_kind' => 'credit_card_expense',
            'id' => (int) $purchase->id,
            'status' => 'effective',
            'movement_date' => $purchase->purchase_date->toDateString(),
            'amount' => MoneyResource::shape((int) $purchase->total_amount_centavos, (string) $purchase->currency_code),
            'description' => (string) $purchase->description,
            'account' => null,
            'credit_card' => [
                'id' => (int) $purchase->credit_card_id,
                'name' => $purchase->creditCard?->name ?? $purchase->card_name_snapshot,
                'status' => $purchase->creditCard?->status->value,
            ],
            'category' => DashboardCategoryResource::shape($purchase->category),
            'recurrence_source' => $occurrence === null ? null : [
                'id' => (int) $occurrence->recurring_transaction_id,
                'scheduled_date' => $occurrence->scheduled_date->toDateString(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function transactionEntry(Transaction $transaction): array
    {
        return [
            'movement_kind' => $transaction->type->value,
            'id' => (int) $transaction->id,
            'status' => $transaction->status->value,
            'movement_date' => $transaction->transaction_date->toDateString(),
            'amount' => MoneyResource::shape((int) $transaction->amount_centavos, (string) $transaction->currency_code),
            'description' => (string) $transaction->description,
            'account' => DashboardAccountResource::shape($transaction->financialAccount),
            'category' => DashboardCategoryResource::shape($transaction->category),
            'recurrence_source' => $transaction->isGeneratedFromRecurrence()
                ? [
                    'id' => (int) $transaction->recurring_transaction_id,
                    'scheduled_date' => $transaction->recurrence_scheduled_date?->toDateString(),
                ]
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function transferEntry(Transfer $transfer): array
    {
        return [
            'movement_kind' => 'transfer',
            'id' => (int) $transfer->id,
            'status' => $transfer->status->value,
            'movement_date' => $transfer->transfer_date->toDateString(),
            'amount' => MoneyResource::shape((int) $transfer->amount_centavos, (string) $transfer->currency_code),
            'description' => $transfer->description !== null ? (string) $transfer->description : null,
            'source_account' => DashboardAccountResource::shape($transfer->sourceAccount),
            'destination_account' => DashboardAccountResource::shape($transfer->destinationAccount),
            'category' => null,
            'recurrence_source' => null,
        ];
    }
}
