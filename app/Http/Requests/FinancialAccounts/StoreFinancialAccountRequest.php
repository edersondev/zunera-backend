<?php

declare(strict_types=1);

namespace App\Http\Requests\FinancialAccounts;

use App\Data\FinancialAccounts\CreateFinancialAccountData;
use App\Enums\FinancialAccounts\AccountType;
use App\Services\FinancialAccounts\FinancialAccountMoney;
use App\Services\FinancialAccounts\FinancialAccountNameNormalizer;
use App\Services\FinancialAccounts\FinancialAccountVisualOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreFinancialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $name = trim((string) $this->input('name'));
            $this->merge([
                'name' => $name,
                'name_normalized' => FinancialAccountNameNormalizer::normalize($name),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'institution_name' => ['nullable', 'string', 'min:1', 'max:120'],
            'color' => ['nullable', Rule::in(FinancialAccountVisualOptions::colors())],
            'icon' => ['nullable', Rule::in(FinancialAccountVisualOptions::icons())],
            'initial_balance_centavos' => [
                'required',
                'integer',
                'min:'.FinancialAccountMoney::MIN_CENTAVOS,
                'max:'.FinancialAccountMoney::MAX_CENTAVOS,
            ],
            'name_normalized' => ['required', 'string', 'max:120'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('name')) {
                    return;
                }

                $duplicateExists = DB::table('financial_accounts')
                    ->where('user_id', (int) $this->user()->id)
                    ->where('active_normalized_name', $this->validated('name_normalized'))
                    ->exists();

                if ($duplicateExists) {
                    $validator->errors()->add('name', 'An active account with this name already exists.');
                }
            },
        ];
    }

    public function toData(): CreateFinancialAccountData
    {
        $validated = $this->validated();

        return new CreateFinancialAccountData(
            userId: (int) $this->user()->id,
            name: (string) $validated['name'],
            accountType: AccountType::from((string) $validated['account_type']),
            initialBalanceCentavos: (int) $validated['initial_balance_centavos'],
            institutionName: isset($validated['institution_name']) ? (string) $validated['institution_name'] : null,
            color: isset($validated['color']) ? (string) $validated['color'] : null,
            icon: isset($validated['icon']) ? (string) $validated['icon'] : null,
        );
    }
}
