<?php

declare(strict_types=1);

namespace App\Http\Requests\FinancialAccounts;

use App\Data\FinancialAccounts\UpdateFinancialAccountData;
use App\Enums\FinancialAccounts\AccountType;
use App\Services\FinancialAccounts\FinancialAccountMoney;
use App\Services\FinancialAccounts\FinancialAccountVisualOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateFinancialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:120'],
            'account_type' => ['sometimes', 'required', Rule::enum(AccountType::class)],
            'institution_name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:120'],
            'color' => ['sometimes', 'nullable', Rule::in(FinancialAccountVisualOptions::colors())],
            'icon' => ['sometimes', 'nullable', Rule::in(FinancialAccountVisualOptions::icons())],
            'initial_balance_centavos' => [
                'sometimes',
                'required',
                'integer',
                'min:'.FinancialAccountMoney::MIN_CENTAVOS,
                'max:'.FinancialAccountMoney::MAX_CENTAVOS,
            ],
        ];
    }

    public function toData(): UpdateFinancialAccountData
    {
        $validated = $this->validated();

        if (array_key_exists('account_type', $validated)) {
            $validated['account_type'] = AccountType::from((string) $validated['account_type']);
        }

        return new UpdateFinancialAccountData($validated);
    }
}
