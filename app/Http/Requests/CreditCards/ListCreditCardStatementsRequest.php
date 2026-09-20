<?php

declare(strict_types=1);

namespace App\Http\Requests\CreditCards;

use App\Enums\CreditCards\CreditCardStatementStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListCreditCardStatementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(CreditCardStatementStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function status(): ?CreditCardStatementStatus
    {
        $status = $this->validated('status');

        return $status === null ? null : CreditCardStatementStatus::from((string) $status);
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 50);
    }
}
