<?php

declare(strict_types=1);

namespace App\Http\Requests\CreditCards;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListCreditCardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'view' => ['nullable', 'string', Rule::in(['active', 'archived'])],
        ];
    }

    public function view(): string
    {
        return (string) ($this->validated('view') ?? 'active');
    }
}
