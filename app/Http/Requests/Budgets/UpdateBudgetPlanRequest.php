<?php

declare(strict_types=1);

namespace App\Http\Requests\Budgets;

use App\Data\Budgets\UpdateBudgetPlanData;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateBudgetPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['planned_amount_centavos' => ['required', 'integer', 'min:1', 'max:99999999999']];
    }

    public function toData(int $budgetId, int $planId): UpdateBudgetPlanData
    {
        return new UpdateBudgetPlanData($budgetId, $planId, (int) $this->validated('planned_amount_centavos'));
    }
}
