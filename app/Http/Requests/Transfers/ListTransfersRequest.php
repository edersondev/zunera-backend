<?php

declare(strict_types=1);

namespace App\Http\Requests\Transfers;

use App\Data\Transfers\TransferFilterData;
use App\Enums\Transfers\TransferStatus;
use App\Services\Transfers\TransferDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListTransfersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'string', 'max:10'],
            'to' => ['nullable', 'string', 'max:10'],
            'source_financial_account_id' => ['nullable', 'integer', 'min:1'],
            'destination_financial_account_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(TransferStatus::class)],
            'q' => ['nullable', 'string', 'max:200'],
            'view' => ['nullable', Rule::in(['active', 'removed'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function toData(): TransferFilterData
    {
        $data = $this->validated();

        return new TransferFilterData(
            from: isset($data['from']) ? TransferDateRange::normalize($data['from'], 'from') : null,
            to: isset($data['to']) ? TransferDateRange::normalize($data['to'], 'to') : null,
            sourceFinancialAccountId: isset($data['source_financial_account_id']) ? (int) $data['source_financial_account_id'] : null,
            destinationFinancialAccountId: isset($data['destination_financial_account_id']) ? (int) $data['destination_financial_account_id'] : null,
            status: isset($data['status']) ? TransferStatus::from($data['status']) : null,
            search: isset($data['q']) ? trim($data['q']) : null,
            view: $data['view'] ?? 'active',
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 50),
        );
    }
}
