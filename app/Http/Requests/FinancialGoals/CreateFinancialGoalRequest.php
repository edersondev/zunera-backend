<?php

declare(strict_types=1);

namespace App\Http\Requests\FinancialGoals;

use App\Data\FinancialGoals\GoalInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CreateFinancialGoalRequest extends FormRequest
{
    use RejectsUnknownGoalFields;

    private bool $nameInputTooLong = false;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        if (is_string($name)) {
            $this->nameInputTooLong = mb_strlen($name) > 200;
            $this->merge(['name' => trim($name)]);
        }
        $this->merge(['idempotency_key' => trim((string) $this->header('Idempotency-Key'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $centavos = static fn (string $attribute, mixed $value, \Closure $fail) => is_int($value) && $value >= 1 && $value <= GoalInput::MAX_CENTAVOS ? null : $fail('The '.$attribute.' must be an integer amount from 1 to '.GoalInput::MAX_CENTAVOS.' centavos.');
        $initial = static fn (string $attribute, mixed $value, \Closure $fail) => is_int($value) && $value >= 0 && $value <= GoalInput::MAX_CENTAVOS ? null : $fail('The '.$attribute.' must be an integer amount from 0 to '.GoalInput::MAX_CENTAVOS.' centavos.');

        return [
            'name' => ['required', 'string', 'max:200'],
            'target_centavos' => ['required', $centavos],
            'initial_allocated_centavos' => ['sometimes', $initial],
            'target_date' => ['nullable', 'date_format:Y-m-d'],
            'financial_account_id' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->rejectUnknownFields($validator, ['name', 'target_centavos', 'initial_allocated_centavos', 'target_date', 'financial_account_id', 'description', 'idempotency_key']);
            if ($this->nameInputTooLong) {
                $validator->errors()->add('name', 'The name must not exceed 200 characters before trimming.');
            }
        }];
    }

    public function toInput(): GoalInput
    {
        return GoalInput::fromValidated($this->validated());
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
