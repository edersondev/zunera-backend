<?php

declare(strict_types=1);

namespace App\Http\Requests\FinancialHistory;

use App\Data\FinancialHistory\FinancialHistoryFilterData;
use App\Enums\Transactions\TransactionStatus;
use App\Services\Transfers\TransferDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListFinancialHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'movement_kind' => ['nullable', Rule::in(['all', 'income', 'expense', 'transfer'])],
            'type' => ['nullable', Rule::in(['income', 'expense'])],
            'from' => ['nullable', 'string', 'max:10'],
            'to' => ['nullable', 'string', 'max:10'],
            'financial_account_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'q' => ['nullable', 'string', 'max:200'],
            'view' => ['nullable', Rule::in(['active', 'removed'])],
            'include' => ['nullable', Rule::in(['recurring'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function toData(): FinancialHistoryFilterData
    {
        $data = $this->validated();

        return new FinancialHistoryFilterData(
            movementKind: $data['movement_kind'] ?? $data['type'] ?? 'all',
            from: isset($data['from']) ? TransferDateRange::normalize($data['from'], 'from') : null,
            to: isset($data['to']) ? TransferDateRange::normalize($data['to'], 'to') : null,
            financialAccountId: isset($data['financial_account_id']) ? (int) $data['financial_account_id'] : null,
            status: isset($data['status']) ? TransactionStatus::from($data['status']) : null,
            search: isset($data['q']) ? trim($data['q']) : null,
            categoryId: isset($data['category_id']) ? (int) $data['category_id'] : null,
            view: $data['view'] ?? 'active',
            include: $data['include'] ?? null,
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 50),
        );
    }
}
