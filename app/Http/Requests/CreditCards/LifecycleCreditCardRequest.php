<?php

declare(strict_types=1);

namespace App\Http\Requests\CreditCards;

use Illuminate\Foundation\Http\FormRequest;

final class LifecycleCreditCardRequest extends FormRequest
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
        return ['idempotency_key' => ['required', 'string', 'min:1', 'max:255']];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
