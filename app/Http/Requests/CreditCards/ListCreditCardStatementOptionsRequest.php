<?php

declare(strict_types=1);

namespace App\Http\Requests\CreditCards;

use App\Services\CreditCards\CreditCardPurchaseDateRange;
use Illuminate\Foundation\Http\FormRequest;

final class ListCreditCardStatementOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'purchase_date' => ['required', 'string', 'max:10'],
            'from_month' => ['sometimes', 'required', 'string', 'regex:/^(?!0000)\d{4}-(0[1-9]|1[0-2])$/'],
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:24'],
        ];
    }

    public function purchaseDate(): string
    {
        return CreditCardPurchaseDateRange::normalize($this->validated('purchase_date'));
    }

    public function fromMonth(): ?string
    {
        return $this->validated('from_month');
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 12);
    }
}
