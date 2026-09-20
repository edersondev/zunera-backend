<?php

declare(strict_types=1);

namespace App\Http\Requests\CreditCards;

use App\Data\CreditCards\UpdateStatementPaymentData;
use App\Services\CreditCards\CreditCardMoney;
use App\Services\CreditCards\CreditCardPurchaseDateRange;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCreditCardStatementPaymentRequest extends FormRequest
{
    /** @var list<string> */
    private const CHANGES = ['financial_account_id', 'amount_centavos', 'payment_date', 'notes', 'status'];

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
            'financial_account_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'amount_centavos' => ['sometimes', 'required', 'integer', 'min:'.CreditCardMoney::MIN_AMOUNT_CENTAVOS, 'max:'.CreditCardMoney::MAX_AMOUNT_CENTAVOS],
            'payment_date' => ['sometimes', 'required', 'string', 'max:10'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['pending', 'effective'])],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_intersect(array_keys($this->all()), self::CHANGES) === []) {
                $validator->errors()->add('amount_centavos', 'Provide at least one payment field to update.');
            }
        });
    }

    public function toData(): UpdateStatementPaymentData
    {
        $data = $this->validated();
        $changes = [];

        foreach (self::CHANGES as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $changes[$field] = match ($field) {
                'payment_date' => CreditCardPurchaseDateRange::normalize((string) $data[$field], 'payment_date'),
                'notes' => $data[$field] === null ? null : trim((string) $data[$field]),
                'status' => (string) $data[$field],
                default => (int) $data[$field],
            };
        }

        return new UpdateStatementPaymentData($changes);
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
