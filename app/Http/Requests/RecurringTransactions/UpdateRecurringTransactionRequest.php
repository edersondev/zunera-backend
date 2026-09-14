<?php

declare(strict_types=1);

namespace App\Http\Requests\RecurringTransactions;

use App\Data\RecurringTransactions\UpdateRecurringTransactionData;
use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\Transactions\TransactionType;
use App\Models\RecurringTransaction;
use App\Services\RecurringTransactions\RecurringDateRange;
use App\Services\RecurringTransactions\RecurringTextNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UpdateRecurringTransactionRequest extends FormRequest
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
            'financial_account_id' => ['sometimes', 'integer', 'min:1'],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', Rule::enum(TransactionType::class)],
            'amount_centavos' => ['sometimes', 'integer', 'min:'.RecurringTransaction::MIN_AMOUNT_CENTAVOS, 'max:'.RecurringTransaction::MAX_AMOUNT_CENTAVOS],
            'description' => ['sometimes', 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'frequency' => ['sometimes', Rule::enum(RecurrenceFrequency::class)],
            'start_date' => ['sometimes', 'string', 'max:10'],
            'end_date' => ['sometimes', 'nullable', 'string', 'max:10'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function toData(): UpdateRecurringTransactionData
    {
        $changes = $this->validated();
        unset($changes['idempotency_key']);

        if ($changes === []) {
            throw ValidationException::withMessages(['recurring_transaction' => ['Provide at least one recurring transaction field to update.']]);
        }
        if (isset($changes['type'])) {
            $changes['type'] = TransactionType::from($changes['type']);
        }
        if (isset($changes['frequency'])) {
            $changes['frequency'] = RecurrenceFrequency::from($changes['frequency']);
        }
        if (array_key_exists('description', $changes)) {
            $description = RecurringTextNormalizer::normalize((string) $changes['description']);
            if ($description === '') {
                throw ValidationException::withMessages(['description' => ['Provide a description for the recurring transaction.']]);
            }
            $changes['description'] = $description;
        }
        if (array_key_exists('notes', $changes)) {
            $changes['notes'] = RecurringTextNormalizer::trimToNull($changes['notes'] === null ? null : (string) $changes['notes']);
        }
        if (isset($changes['start_date'])) {
            $changes['start_date'] = RecurringDateRange::normalize((string) $changes['start_date'], 'start_date');
        }
        if (array_key_exists('end_date', $changes) && $changes['end_date'] !== null) {
            $changes['end_date'] = RecurringDateRange::normalize((string) $changes['end_date'], 'end_date');
        }

        return new UpdateRecurringTransactionData($changes);
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
