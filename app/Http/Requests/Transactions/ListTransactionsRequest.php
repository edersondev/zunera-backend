<?php

declare(strict_types=1);

namespace App\Http\Requests\Transactions;

use App\Data\Transactions\TransactionFilterData;
use App\Enums\Transactions\TransactionStatus;
use App\Enums\Transactions\TransactionType;
use App\Services\Transactions\TransactionDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListTransactionsRequest extends FormRequest
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
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'financial_account_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'q' => ['nullable', 'string', 'max:200'],
            'view' => ['nullable', Rule::in(['active', 'removed'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function toData(): TransactionFilterData
    {
        $data = $this->validated();

        return new TransactionFilterData(
            from: isset($data['from']) ? TransactionDateRange::normalize($data['from'], 'from') : null,
            to: isset($data['to']) ? TransactionDateRange::normalize($data['to'], 'to') : null,
            type: isset($data['type']) ? TransactionType::from($data['type']) : null,
            financialAccountId: isset($data['financial_account_id']) ? (int) $data['financial_account_id'] : null,
            categoryId: isset($data['category_id']) ? (int) $data['category_id'] : null,
            status: isset($data['status']) ? TransactionStatus::from($data['status']) : null,
            search: isset($data['q']) ? trim($data['q']) : null,
            view: $data['view'] ?? 'active',
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 50),
        );
    }
}
