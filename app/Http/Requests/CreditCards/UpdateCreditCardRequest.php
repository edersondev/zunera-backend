<?php

declare(strict_types=1);

namespace App\Http\Requests\CreditCards;

use App\Data\CreditCards\UpdateCreditCardData;
use App\Services\CreditCards\CreditCardMoney;
use App\Services\CreditCards\CreditCardVisualOptions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCreditCardRequest extends FormRequest
{
    /** @var list<string> */
    private const CHANGES = ['name', 'institution_name', 'last_four', 'color', 'icon', 'credit_limit_centavos', 'closing_day', 'due_day'];

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
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:100'],
            'institution_name' => ['sometimes', 'required', 'string', 'min:1', 'max:100'],
            'last_four' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{4}$/'],
            'color' => ['sometimes', 'nullable', 'string', Rule::in(CreditCardVisualOptions::colors())],
            'icon' => ['sometimes', 'nullable', 'string', Rule::in(CreditCardVisualOptions::icons())],
            'credit_limit_centavos' => ['sometimes', 'required', 'integer', 'min:'.CreditCardMoney::MIN_LIMIT_CENTAVOS, 'max:'.CreditCardMoney::MAX_LIMIT_CENTAVOS],
            'closing_day' => ['sometimes', 'required', 'integer', 'min:'.CreditCardMoney::MIN_BILLING_DAY, 'max:'.CreditCardMoney::MAX_BILLING_DAY],
            'due_day' => ['sometimes', 'required', 'integer', 'min:'.CreditCardMoney::MIN_BILLING_DAY, 'max:'.CreditCardMoney::MAX_BILLING_DAY],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
            'card_number' => ['prohibited'],
            'cvv' => ['prohibited'],
            'pin' => ['prohibited'],
            'security_code' => ['prohibited'],
            'expiration_date' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $provided = array_values(array_intersect(array_keys($this->all()), self::CHANGES));
            if ($provided === []) {
                $validator->errors()->add('name', 'Provide at least one card field to update.');
            }
        });
    }

    public function toData(): UpdateCreditCardData
    {
        $data = $this->validated();
        $changes = [];

        foreach (self::CHANGES as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $changes[$field] = match ($field) {
                'name', 'institution_name' => trim((string) $data[$field]),
                'color' => $data[$field] === null ? null : CreditCardVisualOptions::color((string) $data[$field]),
                'icon' => $data[$field] === null ? null : CreditCardVisualOptions::icon((string) $data[$field]),
                'last_four' => $data[$field] === null ? null : (string) $data[$field],
                default => (int) $data[$field],
            };
        }

        return new UpdateCreditCardData($changes);
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
