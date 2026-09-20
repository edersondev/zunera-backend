<?php

declare(strict_types=1);

namespace App\Http\Requests\CreditCards;

use App\Data\CreditCards\CreateStatementPaymentData;
use App\Enums\CreditCards\CreditCardPaymentStatus;
use App\Services\CreditCards\CreditCardMoney;
use App\Services\CreditCards\CreditCardPurchaseDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCreditCardStatementPaymentRequest extends FormRequest
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
            'financial_account_id' => ['required', 'integer', 'min:1'],
            'amount_centavos' => ['required', 'integer', 'min:'.CreditCardMoney::MIN_AMOUNT_CENTAVOS, 'max:'.CreditCardMoney::MAX_AMOUNT_CENTAVOS],
            'payment_date' => ['required', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', Rule::in(['pending', 'effective'])],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function toData(): CreateStatementPaymentData
    {
        $data = $this->validated();

        return new CreateStatementPaymentData(
            userId: (int) $this->user()->id,
            financialAccountId: (int) $data['financial_account_id'],
            amountCentavos: (int) $data['amount_centavos'],
            paymentDate: CreditCardPurchaseDateRange::normalize((string) $data['payment_date'], 'payment_date'),
            notes: isset($data['notes']) ? trim((string) $data['notes']) : null,
            status: isset($data['status']) ? CreditCardPaymentStatus::from((string) $data['status']) : null,
        );
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
