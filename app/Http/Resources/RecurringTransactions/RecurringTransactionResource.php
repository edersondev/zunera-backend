<?php

declare(strict_types=1);

namespace App\Http\Resources\RecurringTransactions;

use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Models\RecurringTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RecurringTransaction */
final class RecurringTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RecurringTransaction $rule */
        $rule = $this->resource;
        /** @var FinancialAccount|null $financialAccount */
        $financialAccount = $rule->financialAccount;
        /** @var CreditCard|null $creditCard */
        $creditCard = $rule->creditCard;

        return [
            'id' => $rule->id,
            'type' => $rule->type->value,
            'destination_type' => $rule->destinationType()->value,
            'amount_centavos' => $rule->amount_centavos,
            'currency_code' => $rule->currency_code,
            'description' => $rule->description,
            'notes' => $rule->notes,
            'frequency' => $rule->frequency->value,
            'start_date' => $rule->start_date->toDateString(),
            'end_date' => $rule->endDateOrNull(),
            'state' => $rule->state->value,
            'paused_reason' => $rule->paused_reason?->value,
            'financial_account' => $financialAccount?->id !== null ? [
                'id' => $financialAccount->id,
                'name' => $financialAccount->name,
                'status' => $financialAccount->status->value,
            ] : null,
            'credit_card' => $creditCard?->id !== null ? [
                'id' => $creditCard->id,
                'name' => $creditCard->name,
                'institution_name' => $creditCard->institution_name,
                'last_four' => $creditCard->last_four,
                'status' => $creditCard->status->value,
            ] : null,
            'generation_mode' => $rule->generationModeOrAutomatic()?->value,
            'category' => [
                'id' => $rule->category?->id,
                'name' => $rule->category?->name,
                'classification' => $rule->category?->classification->value,
                'status' => $rule->category?->status->value,
            ],
            'next_expected_occurrence' => $rule->getAttribute('next_expected_occurrence'),
            'generated_occurrence_count' => (int) ($rule->getAttribute('generated_occurrence_count') ?? 0),
            'reviewable_occurrence_count' => (int) ($rule->getAttribute('reviewable_occurrence_count') ?? 0),
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }
}
