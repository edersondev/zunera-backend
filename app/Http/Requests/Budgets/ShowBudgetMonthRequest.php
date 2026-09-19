<?php

declare(strict_types=1);

namespace App\Http\Requests\Budgets;

use Illuminate\Foundation\Http\FormRequest;

final class ShowBudgetMonthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['year' => $this->route('year'), 'month' => $this->route('month')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:1900,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
        ];
    }

    public function year(): int
    {
        return (int) $this->validated('year');
    }

    public function month(): int
    {
        return (int) $this->validated('month');
    }
}
