<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialHistory;

use App\Http\Resources\RecurringTransactions\RecurringTransactionResource;
use App\Models\CreditCardInstallment;
use App\Models\FinancialAccount;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Discriminated read projection shared by the mixed financial-history endpoint.
 * Transfers use movement_kind = transfer, carry both account sides, never carry a
 * category, and never participate in income/expense signage.
 * Recognized card installments use movement_kind = credit_card_expense on their
 * statement closing date; statement payments are settlement and never appear.
 *
 * @mixin Transaction|Transfer|RecurringTransaction|CreditCardInstallment
 */
final class FinancialHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $movement = $this->resource;

        return match (true) {
            $movement instanceof Transfer => $this->transferEntry($movement),
            $movement instanceof RecurringTransaction => $this->recurringEntry($movement),
            $movement instanceof CreditCardInstallment => $this->creditCardExpenseEntry($movement),
            default => $this->transactionEntry($movement),
        };
    }

    /** @return array<string, mixed> */
    private function creditCardExpenseEntry(CreditCardInstallment $installment): array
    {
        $purchase = $installment->purchase;
        $statement = $installment->statement;

        return [
            'movement_kind' => 'credit_card_expense',
            'id' => $installment->id,
            'amount_centavos' => $installment->netAmountCentavos(),
            'currency_code' => $purchase->currency_code,
            'movement_date' => $statement?->closing_date?->toDateString(),
            'status' => 'effective',
            'description' => $purchase->description,
            'notes' => $purchase->notes,
            'installment' => [
                'sequence' => $installment->sequence,
                'total_count' => $purchase->installment_count,
                'credit_adjustment_centavos' => $installment->credit_adjustment_centavos,
            ],
            'credit_card' => [
                'id' => $purchase->creditCard?->id,
                'name' => $purchase->creditCard?->name,
                'status' => $purchase->creditCard?->status->value,
            ],
            'statement' => $statement === null ? null : [
                'id' => $statement->id,
                'closing_date' => $statement->closing_date->toDateString(),
                'due_date' => $statement->due_date->toDateString(),
                'status' => $statement->status->value,
            ],
            'financial_account' => null,
            'category' => [
                'id' => $purchase->category?->id,
                'name' => $purchase->category?->name ?? $purchase->category_name_snapshot,
                'classification' => $purchase->category?->classification->value ?? 'expense',
                'status' => $purchase->category?->status->value ?? $purchase->category_status_snapshot,
            ],
        ];
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
            'recurrence_source' => $transaction->isGeneratedFromRecurrence()
                ? [
                    'id' => (int) $transaction->recurring_transaction_id,
                    'scheduled_date' => $transaction->recurrence_scheduled_date?->toDateString(),
                ]
                : null,
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
    private function recurringEntry(RecurringTransaction $rule): array
    {
        return [
            'movement_kind' => 'recurring',
            'movement_date' => $rule->getAttribute('next_expected_occurrence'),
            ...(new RecurringTransactionResource($rule))->resolve(),
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
