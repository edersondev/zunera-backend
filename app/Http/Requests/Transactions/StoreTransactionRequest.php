<?php

declare(strict_types=1);

namespace App\Http\Requests\Transactions;

use App\Data\Transactions\CreateTransactionData;
use App\Enums\Transactions\TransactionStatus;
use App\Enums\Transactions\TransactionType;
use App\Services\Transactions\TransactionDateRange;
use App\Services\Transactions\TransactionMoney;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTransactionRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'description' => ['required', 'string', 'min:1', 'max:200'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'amount_centavos' => ['required', 'integer', 'min:'.TransactionMoney::MIN_CENTAVOS, 'max:'.TransactionMoney::MAX_CENTAVOS],
            'transaction_date' => ['required', 'string', 'max:10'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function toData(): CreateTransactionData
    {
        $data = $this->validated();

        return new CreateTransactionData(
            userId: (int) $this->user()->id,
            financialAccountId: (int) $data['financial_account_id'],
            categoryId: (int) $data['category_id'],
            type: TransactionType::from($data['type']),
            description: trim($data['description']),
            notes: isset($data['notes']) ? trim((string) $data['notes']) : null,
            amountCentavos: (int) $data['amount_centavos'],
            transactionDate: TransactionDateRange::normalize($data['transaction_date']),
            status: isset($data['status']) ? TransactionStatus::from($data['status']) : null,
        );
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
