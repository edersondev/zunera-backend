<?php

declare(strict_types=1);

namespace App\Http\Requests\CreditCards;

use App\Data\CreditCards\CreateCreditCardData;
use App\Services\CreditCards\CreditCardMoney;
use App\Services\CreditCards\CreditCardVisualOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCreditCardRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'institution_name' => ['required', 'string', 'min:1', 'max:100'],
            'last_four' => ['nullable', 'string', 'regex:/^[0-9]{4}$/'],
            'color' => ['nullable', 'string', Rule::in(CreditCardVisualOptions::colors())],
            'icon' => ['nullable', 'string', Rule::in(CreditCardVisualOptions::icons())],
            'credit_limit_centavos' => ['required', 'integer', 'min:'.CreditCardMoney::MIN_LIMIT_CENTAVOS, 'max:'.CreditCardMoney::MAX_LIMIT_CENTAVOS],
            'closing_day' => ['required', 'integer', 'min:'.CreditCardMoney::MIN_BILLING_DAY, 'max:'.CreditCardMoney::MAX_BILLING_DAY],
            'due_day' => ['required', 'integer', 'min:'.CreditCardMoney::MIN_BILLING_DAY, 'max:'.CreditCardMoney::MAX_BILLING_DAY],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
            'card_number' => ['prohibited'],
            'cvv' => ['prohibited'],
            'pin' => ['prohibited'],
            'security_code' => ['prohibited'],
            'expiration_date' => ['prohibited'],
        ];
    }

    public function toData(): CreateCreditCardData
    {
        $data = $this->validated();

        return new CreateCreditCardData(
            userId: (int) $this->user()->id,
            name: trim((string) $data['name']),
            institutionName: trim((string) $data['institution_name']),
            lastFour: isset($data['last_four']) && $data['last_four'] !== null ? (string) $data['last_four'] : null,
            color: CreditCardVisualOptions::color(isset($data['color']) ? (string) $data['color'] : null),
            icon: CreditCardVisualOptions::icon(isset($data['icon']) ? (string) $data['icon'] : null),
            creditLimitCentavos: (int) $data['credit_limit_centavos'],
            closingDay: (int) $data['closing_day'],
            dueDay: (int) $data['due_day'],
        );
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
