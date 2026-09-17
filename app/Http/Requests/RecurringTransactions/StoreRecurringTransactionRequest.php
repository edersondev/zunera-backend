<?php

declare(strict_types=1);

namespace App\Http\Requests\RecurringTransactions;

use App\Data\RecurringTransactions\CreateRecurringTransactionData;
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
            'financial_account_id' => ['required', 'integer', 'min:1'],
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

        if ($description === '') {
            throw ValidationException::withMessages(['description' => ['Provide a description for the recurring transaction.']]);
        }
        if ($endDate !== null && $endDate < $startDate) {
            throw ValidationException::withMessages(['end_date' => ['End date must be on or after the start date.']]);
        }

        return new CreateRecurringTransactionData(
            userId: (int) $this->user()->id,
            financialAccountId: (int) $data['financial_account_id'],
            categoryId: (int) $data['category_id'],
            type: TransactionType::from($data['type']),
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
