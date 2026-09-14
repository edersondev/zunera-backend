<?php

declare(strict_types=1);

namespace App\Http\Requests\Transfers;

use App\Data\Transfers\CreateTransferData;
use App\Enums\Transfers\TransferStatus;
use App\Services\Transfers\TransferDateRange;
use App\Services\Transfers\TransferMoney;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTransferRequest extends FormRequest
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
            'source_financial_account_id' => ['required', 'integer', 'min:1'],
            'destination_financial_account_id' => ['required', 'integer', 'min:1', 'different:source_financial_account_id'],
            'amount_centavos' => ['required', 'integer', 'min:'.TransferMoney::MIN_CENTAVOS, 'max:'.TransferMoney::MAX_CENTAVOS],
            'transfer_date' => ['required', 'string', 'max:10'],
            'status' => ['nullable', Rule::enum(TransferStatus::class)],
            'description' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function toData(): CreateTransferData
    {
        $data = $this->validated();
        $description = isset($data['description']) ? trim((string) $data['description']) : null;
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : null;

        return new CreateTransferData(
            userId: (int) $this->user()->id,
            sourceFinancialAccountId: (int) $data['source_financial_account_id'],
            destinationFinancialAccountId: (int) $data['destination_financial_account_id'],
            amountCentavos: (int) $data['amount_centavos'],
            transferDate: TransferDateRange::normalize($data['transfer_date']),
            status: isset($data['status']) ? TransferStatus::from($data['status']) : null,
            description: $description === '' ? null : $description,
            notes: $notes === '' ? null : $notes,
        );
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
