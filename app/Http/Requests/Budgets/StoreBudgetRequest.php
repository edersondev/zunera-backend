<?php

declare(strict_types=1);

namespace App\Http\Requests\Budgets;

use App\Data\Budgets\BudgetMonthData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:1900,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
        ];
    }

    public function toData(): BudgetMonthData
    {
        $validated = $this->validated();

        return new BudgetMonthData((int) $this->user()->id, (int) $validated['year'], (int) $validated['month']);
    }
}
