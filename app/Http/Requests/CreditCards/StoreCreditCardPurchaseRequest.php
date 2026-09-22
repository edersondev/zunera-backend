<?php

declare(strict_types=1);

namespace App\Http\Requests\CreditCards;

use App\Data\CreditCards\CreatePurchaseData;
use App\Services\CreditCards\CreditCardMoney;
use App\Services\CreditCards\CreditCardPurchaseDateRange;
use Illuminate\Foundation\Http\FormRequest;

final class StoreCreditCardPurchaseRequest extends FormRequest
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
            'category_id' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'min:1', 'max:200'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'purchase_date' => ['required', 'string', 'max:10'],
            'total_amount_centavos' => ['required', 'integer', 'min:'.CreditCardMoney::MIN_AMOUNT_CENTAVOS, 'max:'.CreditCardMoney::MAX_AMOUNT_CENTAVOS],
            'installment_count' => ['required', 'integer', 'min:'.CreditCardMoney::MIN_INSTALLMENT_COUNT, 'max:'.CreditCardMoney::MAX_INSTALLMENT_COUNT],
            'confirm_over_limit' => ['nullable', 'boolean'],
            'expected_available_credit_centavos' => ['nullable', 'integer'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function toData(int $creditCardId): CreatePurchaseData
    {
        $data = $this->validated();

        return new CreatePurchaseData(
            userId: (int) $this->user()->id,
            creditCardId: $creditCardId,
            categoryId: (int) $data['category_id'],
            description: trim((string) $data['description']),
            notes: isset($data['notes']) ? trim((string) $data['notes']) : null,
            totalAmountCentavos: (int) $data['total_amount_centavos'],
            installmentCount: (int) $data['installment_count'],
            purchaseDate: CreditCardPurchaseDateRange::normalize($data['purchase_date']),
            overLimitConfirmed: (bool) ($data['confirm_over_limit'] ?? false),
            confirmedAvailableCreditCentavos: isset($data['expected_available_credit_centavos'])
                ? (int) $data['expected_available_credit_centavos']
                : null,
        );
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
