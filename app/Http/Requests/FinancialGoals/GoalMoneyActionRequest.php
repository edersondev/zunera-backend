<?php

declare(strict_types=1);

namespace App\Http\Requests\FinancialGoals;

use App\Data\FinancialGoals\GoalInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class GoalMoneyActionRequest extends FormRequest
{
    use RejectsUnknownGoalFields;

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
            'amount_centavos' => ['required', static fn (string $attribute, mixed $value, \Closure $fail) => is_int($value) && $value >= 1 && $value <= GoalInput::MAX_CENTAVOS ? null : $fail('The amount must be a positive integer in centavos.')],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function amountCentavos(): int
    {
        return GoalInput::centavos($this->validated('amount_centavos'));
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->rejectUnknownFields($validator, ['amount_centavos', 'idempotency_key'])];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
