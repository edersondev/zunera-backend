<?php

declare(strict_types=1);

namespace App\Http\Requests\Budgets;

use App\Data\Budgets\CreateBudgetPlanData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreBudgetPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'min:1'],
            'planned_amount_centavos' => ['required', 'integer', 'min:1', 'max:99999999999'],
        ];
    }

    public function toData(int $budgetId): CreateBudgetPlanData
    {
        $validated = $this->validated();

        return new CreateBudgetPlanData($budgetId, (int) $validated['category_id'], (int) $validated['planned_amount_centavos']);
    }
}
