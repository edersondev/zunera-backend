<?php

declare(strict_types=1);

namespace App\Http\Requests\Budgets;

use App\Data\Budgets\CopyBudgetData;
use Illuminate\Foundation\Http\FormRequest;

final class CopyBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'destination_year' => ['required', 'integer', 'between:1900,2100'],
            'destination_month' => ['required', 'integer', 'between:1,12'],
        ];
    }

    public function toData(int $sourceBudgetId): CopyBudgetData
    {
        $validated = $this->validated();

        return new CopyBudgetData(
            (int) $this->user()->id,
            $sourceBudgetId,
            (int) $validated['destination_year'],
            (int) $validated['destination_month'],
        );
    }
}
