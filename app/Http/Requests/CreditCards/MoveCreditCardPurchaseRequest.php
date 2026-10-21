<?php

declare(strict_types=1);

namespace App\Http\Requests\CreditCards;

use App\Data\CreditCards\MovePurchaseData;

final class MoveCreditCardPurchaseRequest extends PreviewCreditCardPurchaseMoveRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => trim((string) $this->header('Idempotency-Key'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'preview_token' => ['required', 'string', 'min:1'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function toData(): MovePurchaseData
    {
        $data = $this->validated();

        return new MovePurchaseData(
            mode: (string) $data['mode'],
            targetClosingDate: (string) $data['target_closing_date'],
            installmentId: isset($data['installment_id']) ? (int) $data['installment_id'] : null,
            previewToken: (string) $data['preview_token'],
        );
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
