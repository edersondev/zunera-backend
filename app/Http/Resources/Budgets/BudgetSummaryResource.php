<?php

declare(strict_types=1);

namespace App\Http\Resources\Budgets;

use App\Data\Budgets\BudgetMonthCalculation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Monthly summary derived entirely from category plans and effective expenses. */
final class BudgetSummaryResource extends JsonResource
{
    public function __construct(private readonly BudgetMonthCalculation $calculation)
    {
        parent::__construct($calculation);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $calculation = $this->calculation;

        return [
            'total_planned' => BudgetMoneyResource::shape($calculation->totalPlannedCentavos),
            'budgeted_realized' => BudgetMoneyResource::shape($calculation->budgetedRealizedCentavos),
            'actual_available' => BudgetMoneyResource::shape($calculation->actualAvailableCentavos),
            'overall_utilization_percent' => $calculation->overallUtilizationPercent,
            'overall_status' => $calculation->overallStatus->value,
            'unbudgeted_expenses' => BudgetMoneyResource::shape($calculation->unbudgetedExpensesCentavos),
            'total_expenses' => BudgetMoneyResource::shape($calculation->totalExpensesCentavos),
            'expected' => $calculation->expectedCentavos === null ? null : BudgetMoneyResource::shape($calculation->expectedCentavos),
            'projected_spending' => $calculation->projectedSpendingCentavos === null ? null : BudgetMoneyResource::shape($calculation->projectedSpendingCentavos),
            'projected_available' => $calculation->projectedAvailableCentavos === null ? null : BudgetMoneyResource::shape($calculation->projectedAvailableCentavos),
            'projected_status' => $calculation->projectedStatus?->value,
        ];
    }
}
