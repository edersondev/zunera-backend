<?php

declare(strict_types=1);

namespace App\Http\Requests\RecurringTransactions;

use App\Data\RecurringTransactions\ConfirmCardOccurrenceData;
use App\Models\RecurringTransaction;
use App\Services\RecurringTransactions\RecurringDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class ConfirmCardOccurrenceRequest extends FormRequest
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
            'actual_amount_centavos' => ['nullable', 'integer', 'min:'.RecurringTransaction::MIN_AMOUNT_CENTAVOS, 'max:'.RecurringTransaction::MAX_AMOUNT_CENTAVOS],
            'actual_purchase_date' => ['nullable', 'string', 'max:10'],
            'credit_card_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'confirm_over_limit' => ['nullable', 'boolean'],
            'expected_available_credit_centavos' => ['nullable', 'integer'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function toData(): ConfirmCardOccurrenceData
    {
        $data = $this->validated();
        unset($data['idempotency_key']);

        $date = null;
        if (isset($data['actual_purchase_date'])) {
            $date = RecurringDateRange::normalize((string) $data['actual_purchase_date'], 'actual_purchase_date');
            if (RecurringDateRange::isFuture($date)) {
                throw ValidationException::withMessages(['actual_purchase_date' => ['The actual purchase date cannot be in the future.']]);
            }
        }

        return new ConfirmCardOccurrenceData(
            actualAmountCentavos: isset($data['actual_amount_centavos']) ? (int) $data['actual_amount_centavos'] : null,
            actualPurchaseDate: $date,
            creditCardId: isset($data['credit_card_id']) ? (int) $data['credit_card_id'] : null,
            categoryId: isset($data['category_id']) ? (int) $data['category_id'] : null,
            confirmOverLimit: (bool) ($data['confirm_over_limit'] ?? false),
            expectedAvailableCreditCentavos: isset($data['expected_available_credit_centavos'])
                ? (int) $data['expected_available_credit_centavos']
                : null,
        );
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
