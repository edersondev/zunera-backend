<?php

declare(strict_types=1);

namespace App\Http\Requests\Transactions;

use App\Data\Transactions\UpdateTransactionData;
use App\Enums\Transactions\TransactionStatus;
use App\Enums\Transactions\TransactionType;
use App\Services\Transactions\TransactionDateRange;
use App\Services\Transactions\TransactionMoney;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTransactionRequest extends FormRequest
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
            'status' => ['sometimes', Rule::enum(TransactionStatus::class)],
            'description' => ['sometimes', 'string', 'min:1', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'amount_centavos' => ['sometimes', 'integer', 'min:'.TransactionMoney::MIN_CENTAVOS, 'max:'.TransactionMoney::MAX_CENTAVOS],
            'transaction_date' => ['sometimes', 'string', 'max:10'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function toData(): UpdateTransactionData
    {
        $changes = $this->validated();
        unset($changes['idempotency_key']);

        if ($changes === []) {
            abort(422, 'Provide at least one transaction field to update.');
        }
        if (isset($changes['type'])) {
            $changes['type'] = TransactionType::from($changes['type']);
        }
        if (isset($changes['status'])) {
            $changes['status'] = TransactionStatus::from($changes['status']);
        }
        if (isset($changes['description'])) {
            $changes['description'] = trim($changes['description']);
        }
        if (array_key_exists('notes', $changes) && $changes['notes'] !== null) {
            $changes['notes'] = trim($changes['notes']);
        }
        if (isset($changes['transaction_date'])) {
            $changes['transaction_date'] = TransactionDateRange::normalize($changes['transaction_date']);
        }

        return new UpdateTransactionData($changes);
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
