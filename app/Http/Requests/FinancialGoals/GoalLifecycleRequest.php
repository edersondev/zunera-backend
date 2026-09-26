<?php

declare(strict_types=1);

namespace App\Http\Requests\FinancialGoals;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class GoalLifecycleRequest extends FormRequest
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
        return ['idempotency_key' => ['required', 'string', 'min:1', 'max:255']];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->rejectUnknownFields($validator, ['idempotency_key'])];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
