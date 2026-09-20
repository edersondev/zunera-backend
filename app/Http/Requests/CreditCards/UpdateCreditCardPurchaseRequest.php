<?php

declare(strict_types=1);

namespace App\Http\Requests\CreditCards;

use App\Data\CreditCards\UpdatePurchaseData;
use App\Services\CreditCards\CreditCardMoney;
use App\Services\CreditCards\CreditCardPurchaseDateRange;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateCreditCardPurchaseRequest extends FormRequest
{
    /** @var list<string> */
    private const CHANGES = ['card_id', 'category_id', 'description', 'notes', 'purchase_date', 'total_amount_centavos', 'installment_count'];

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
            'card_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'category_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'description' => ['sometimes', 'required', 'string', 'min:1', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'purchase_date' => ['sometimes', 'required', 'string', 'max:10'],
            'total_amount_centavos' => ['sometimes', 'required', 'integer', 'min:'.CreditCardMoney::MIN_AMOUNT_CENTAVOS, 'max:'.CreditCardMoney::MAX_AMOUNT_CENTAVOS],
            'installment_count' => ['sometimes', 'required', 'integer', 'min:'.CreditCardMoney::MIN_INSTALLMENT_COUNT, 'max:'.CreditCardMoney::MAX_INSTALLMENT_COUNT],
            'confirm_over_limit' => ['nullable', 'boolean'],
            'expected_available_credit_centavos' => ['nullable', 'integer'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_intersect(array_keys($this->all()), self::CHANGES) === []) {
                $validator->errors()->add('description', 'Provide at least one purchase field to correct.');
            }
        });
    }

    public function toData(): UpdatePurchaseData
    {
        $data = $this->validated();
        $changes = [];

        foreach (self::CHANGES as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $changes[$field] = match ($field) {
                'description' => trim((string) $data[$field]),
                'notes' => $data[$field] === null ? null : trim((string) $data[$field]),
                'purchase_date' => CreditCardPurchaseDateRange::normalize((string) $data[$field]),
                default => (int) $data[$field],
            };
        }

        return new UpdatePurchaseData($changes);
    }

    public function overLimitConfirmed(): bool
    {
        return (bool) ($this->validated('confirm_over_limit') ?? false);
    }

    public function expectedAvailableCreditCentavos(): ?int
    {
        $value = $this->validated('expected_available_credit_centavos');

        return $value === null ? null : (int) $value;
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
