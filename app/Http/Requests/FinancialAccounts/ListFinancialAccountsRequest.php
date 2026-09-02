<?php

declare(strict_types=1);

namespace App\Http\Requests\FinancialAccounts;

use App\Enums\FinancialAccounts\AccountStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListFinancialAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', Rule::enum(AccountStatus::class)],
        ];
    }

    public function status(): AccountStatus
    {
        return AccountStatus::from((string) $this->validated('status', AccountStatus::Active->value));
    }
}
