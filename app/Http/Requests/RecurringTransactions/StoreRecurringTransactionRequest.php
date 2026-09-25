<?php

declare(strict_types=1);

namespace App\Http\Requests\RecurringTransactions;

use App\Data\RecurringTransactions\CreateRecurringTransactionData;
use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\RecurrenceDestinationType;
use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\Transactions\TransactionType;
use App\Models\RecurringTransaction;
use App\Services\RecurringTransactions\RecurringDateRange;
use App\Services\RecurringTransactions\RecurringTextNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StoreRecurringTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => trim((string) $this->header('Idempotency-Key'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'destination_type' => ['nullable', Rule::enum(RecurrenceDestinationType::class)],
            'financial_account_id' => ['nullable', 'integer', 'min:1'],
            'credit_card_id' => ['nullable', 'integer', 'min:1'],
            'generation_mode' => ['nullable', Rule::enum(CardGenerationMode::class)],
            'category_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::enum(TransactionType::class)],
            'amount_centavos' => ['required', 'integer', 'min:'.RecurringTransaction::MIN_AMOUNT_CENTAVOS, 'max:'.RecurringTransaction::MAX_AMOUNT_CENTAVOS],
            'description' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'frequency' => ['required', Rule::enum(RecurrenceFrequency::class)],
            'start_date' => ['required', 'string', 'max:10'],
            'end_date' => ['nullable', 'string', 'max:10'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function toData(): CreateRecurringTransactionData
    {
        $data = $this->validated();
        $description = RecurringTextNormalizer::normalize((string) $data['description']);
        $startDate = RecurringDateRange::normalize((string) $data['start_date'], 'start_date');
        $endDate = isset($data['end_date']) ? RecurringDateRange::normalize((string) $data['end_date'], 'end_date') : null;
        $destinationType = RecurrenceDestinationType::from($data['destination_type'] ?? RecurrenceDestinationType::FinancialAccount->value);

        if ($description === '') {
            throw ValidationException::withMessages(['description' => ['Provide a description for the recurring transaction.']]);
        }
        if ($endDate !== null && $endDate < $startDate) {
            throw ValidationException::withMessages(['end_date' => ['End date must be on or after the start date.']]);
        }

        $type = TransactionType::from($data['type']);
        if ($destinationType->isCard()) {
            if (array_key_exists('financial_account_id', $data) && $data['financial_account_id'] !== null) {
                throw ValidationException::withMessages(['financial_account_id' => ['Card rules cannot select a financial account.']]);
            }
            if (! isset($data['credit_card_id'])) {
                throw ValidationException::withMessages(['credit_card_id' => ['Select a credit card for the recurring expense.']]);
            }
            if ($type !== TransactionType::Expense) {
                throw ValidationException::withMessages(['type' => ['Credit card recurring rules must be expenses.']]);
            }
            $financialAccountId = null;
            $creditCardId = (int) $data['credit_card_id'];
            $generationMode = CardGenerationMode::from($data['generation_mode'] ?? CardGenerationMode::Automatic->value);
        } else {
            if (array_key_exists('credit_card_id', $data) && $data['credit_card_id'] !== null) {
                throw ValidationException::withMessages(['credit_card_id' => ['Account rules cannot select a credit card.']]);
            }
            if (! isset($data['financial_account_id'])) {
                throw ValidationException::withMessages(['financial_account_id' => ['Select a financial account for the recurring transaction.']]);
            }
            $financialAccountId = (int) $data['financial_account_id'];
            $creditCardId = null;
            $generationMode = null;
        }

        return new CreateRecurringTransactionData(
            userId: (int) $this->user()->id,
            destinationType: $destinationType,
            financialAccountId: $financialAccountId,
            creditCardId: $creditCardId,
            generationMode: $generationMode,
            categoryId: (int) $data['category_id'],
            type: $type,
            amountCentavos: (int) $data['amount_centavos'],
            description: $description,
            notes: RecurringTextNormalizer::trimToNull($data['notes'] ?? null),
            frequency: RecurrenceFrequency::from($data['frequency']),
            startDate: $startDate,
            endDate: $endDate,
        );
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
