<?php

declare(strict_types=1);

namespace App\Http\Resources\Budgets;

use App\Data\Budgets\BudgetMonthCalculation;
use App\Models\MonthlyBudget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Selected month envelope. A month without a budget is a normal empty state:
 * the response stays 200 with `budget: null` and never fabricates a zero plan.
 *
 * @property array{period: array<string, mixed>, budget: ?MonthlyBudget, calculation: ?BudgetMonthCalculation} $resource
 */
final class BudgetMonthResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $budget = $this->resource['budget'];
        $calculation = $this->resource['calculation'];

        return [
            'period' => $this->resource['period'],
            'budget' => ($budget instanceof MonthlyBudget && $calculation instanceof BudgetMonthCalculation)
                ? (new BudgetResource($budget, $calculation))->toArray($request)
                : null,
        ];
    }
}
