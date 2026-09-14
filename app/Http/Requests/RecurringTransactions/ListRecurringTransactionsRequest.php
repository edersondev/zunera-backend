<?php

declare(strict_types=1);

namespace App\Http\Requests\RecurringTransactions;

use App\Data\RecurringTransactions\RecurringTransactionFilterData;
use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListRecurringTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'financial_account_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'frequency' => ['nullable', Rule::enum(RecurrenceFrequency::class)],
            'state' => ['nullable', Rule::enum(RecurrenceState::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function toData(): RecurringTransactionFilterData
    {
        $data = $this->validated();

        return new RecurringTransactionFilterData(
            type: isset($data['type']) ? TransactionType::from($data['type']) : null,
            financialAccountId: isset($data['financial_account_id']) ? (int) $data['financial_account_id'] : null,
            categoryId: isset($data['category_id']) ? (int) $data['category_id'] : null,
            frequency: isset($data['frequency']) ? RecurrenceFrequency::from($data['frequency']) : null,
            state: isset($data['state']) ? RecurrenceState::from($data['state']) : null,
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 50),
        );
    }
}
