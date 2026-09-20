<?php

declare(strict_types=1);

namespace App\Http\Requests\CreditCards;

use App\Enums\CreditCards\CreditCardPaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LifecycleCreditCardPaymentRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(CreditCardPaymentStatus::class)],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function status(): ?CreditCardPaymentStatus
    {
        $status = $this->validated('status');

        return $status === null ? null : CreditCardPaymentStatus::from((string) $status);
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
