<?php

declare(strict_types=1);

namespace App\Http\Resources\Budgets;

use App\Data\Budgets\BudgetMonthCalculation;
use App\Models\MonthlyBudget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BudgetResource extends JsonResource
{
    public function __construct(private readonly MonthlyBudget $budget, private readonly BudgetMonthCalculation $calculation)
    {
        parent::__construct($budget);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $plans = [];

        foreach ($this->budget->plans()->with('category')->orderBy('id')->get() as $plan) {
            $calculation = $this->calculation->plans[(int) $plan->id] ?? null;

            if ($calculation === null) {
                continue;
            }

            $plans[] = (new BudgetCategoryPlanResource($plan, $calculation))->toArray($request);
        }

        return [
            'id' => (int) $this->budget->id,
            'period' => [
                'year' => $this->calculation->year,
                'month' => $this->calculation->month,
                'from' => $this->calculation->from,
                'to' => $this->calculation->to,
            ],
            'summary' => (new BudgetSummaryResource($this->calculation))->toArray($request),
            'plans' => $plans,
        ];
    }
}
