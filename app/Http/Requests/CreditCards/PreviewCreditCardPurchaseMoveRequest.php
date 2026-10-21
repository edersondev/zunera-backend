<?php

declare(strict_types=1);

namespace App\Http\Requests\CreditCards;

use App\Data\CreditCards\MovePurchaseData;
use Illuminate\Foundation\Http\FormRequest;

class PreviewCreditCardPurchaseMoveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', 'in:full_schedule,one_installment'],
            'target_closing_date' => ['required', 'string', 'date_format:Y-m-d'],
            'installment_id' => ['required_if:mode,one_installment', 'prohibited_if:mode,full_schedule', 'integer', 'min:1'],
        ];
    }

    public function toData(): MovePurchaseData
    {
        $data = $this->validated();

        return new MovePurchaseData(
            mode: (string) $data['mode'],
            targetClosingDate: (string) $data['target_closing_date'],
            installmentId: isset($data['installment_id']) ? (int) $data['installment_id'] : null,
            previewToken: null,
        );
    }
}
