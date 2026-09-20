<?php

declare(strict_types=1);

namespace App\Http\Requests\CreditCards;

use App\Data\CreditCards\CreateCreditEventData;
use App\Enums\CreditCards\CreditCardCreditEventReason;
use App\Services\CreditCards\CreditCardMoney;
use App\Services\CreditCards\CreditCardPurchaseDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCreditCardCreditEventRequest extends FormRequest
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
            'reason' => ['required', Rule::enum(CreditCardCreditEventReason::class)],
            'amount_centavos' => ['required', 'integer', 'min:'.CreditCardMoney::MIN_AMOUNT_CENTAVOS, 'max:'.CreditCardMoney::MAX_AMOUNT_CENTAVOS],
            'event_date' => ['required', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function toData(): CreateCreditEventData
    {
        $data = $this->validated();

        return new CreateCreditEventData(
            userId: (int) $this->user()->id,
            reason: CreditCardCreditEventReason::from((string) $data['reason']),
            amountCentavos: (int) $data['amount_centavos'],
            eventDate: CreditCardPurchaseDateRange::normalize((string) $data['event_date'], 'event_date'),
            notes: isset($data['notes']) ? trim((string) $data['notes']) : null,
        );
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
