<?php

declare(strict_types=1);

namespace App\Http\Requests\Transfers;

use App\Data\Transfers\UpdateTransferData;
use App\Enums\Transfers\TransferStatus;
use App\Services\Transfers\TransferDateRange;
use App\Services\Transfers\TransferMoney;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTransferRequest extends FormRequest
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
            'source_financial_account_id' => ['sometimes', 'integer', 'min:1'],
            'destination_financial_account_id' => ['sometimes', 'integer', 'min:1'],
            'amount_centavos' => ['sometimes', 'integer', 'min:'.TransferMoney::MIN_CENTAVOS, 'max:'.TransferMoney::MAX_CENTAVOS],
            'transfer_date' => ['sometimes', 'string', 'max:10'],
            'status' => ['sometimes', Rule::enum(TransferStatus::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function toData(): UpdateTransferData
    {
        $changes = $this->validated();
        unset($changes['idempotency_key']);

        if ($changes === []) {
            abort(422, 'Provide at least one transfer field to update.');
        }
        if (isset($changes['status'])) {
            $changes['status'] = TransferStatus::from($changes['status']);
        }
        if (isset($changes['transfer_date'])) {
            $changes['transfer_date'] = TransferDateRange::normalize($changes['transfer_date']);
        }
        if (array_key_exists('description', $changes) && $changes['description'] !== null) {
            $changes['description'] = trim((string) $changes['description']);
            $changes['description'] = $changes['description'] === '' ? null : $changes['description'];
        }
        if (array_key_exists('notes', $changes) && $changes['notes'] !== null) {
            $changes['notes'] = trim((string) $changes['notes']);
            $changes['notes'] = $changes['notes'] === '' ? null : $changes['notes'];
        }

        return new UpdateTransferData($changes);
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
