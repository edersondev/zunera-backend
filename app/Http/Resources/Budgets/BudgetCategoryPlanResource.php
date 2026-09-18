<?php

declare(strict_types=1);

namespace App\Http\Resources\Budgets;

use App\Data\Budgets\BudgetPlanCalculation;
use App\Enums\Categories\CategoryStatus;
use App\Models\BudgetCategoryPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One planned category line. Identity fields come from the immutable plan
 * snapshot; status and read-only behavior always follow the live category.
 */
final class BudgetCategoryPlanResource extends JsonResource
{
    public function __construct(private readonly BudgetCategoryPlan $plan, private readonly BudgetPlanCalculation $calculation)
    {
        parent::__construct($plan);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->plan->loadMissing('category');
        $category = $this->plan->category;
        $archived = $category?->status === CategoryStatus::Archived;
        $calculation = $this->calculation;

        return [
            'id' => $calculation->planId,
            'category' => [
                'id' => $calculation->categoryId,
                'name' => $this->plan->category_name_snapshot,
                'classification' => $this->plan->category_classification_snapshot->value,
                'origin' => $this->plan->category_origin_snapshot->value,
                'status' => $archived ? CategoryStatus::Archived->value : CategoryStatus::Active->value,
                'color' => $this->plan->category_color_snapshot,
                'icon' => $this->plan->category_icon_snapshot,
            ],
            'planned' => BudgetMoneyResource::shape($calculation->plannedCentavos),
            'realized' => BudgetMoneyResource::shape($calculation->realizedCentavos),
            'available' => BudgetMoneyResource::shape($calculation->availableCentavos),
            'utilization_percent' => $calculation->utilizationPercent,
            'status' => $calculation->status->value,
            'excess' => BudgetMoneyResource::shape($calculation->excessCentavos),
            'expected' => $calculation->expectedCentavos === null ? null : BudgetMoneyResource::shape($calculation->expectedCentavos),
            'projected_spending' => $calculation->projectedSpendingCentavos === null ? null : BudgetMoneyResource::shape($calculation->projectedSpendingCentavos),
            'projected_available' => $calculation->projectedAvailableCentavos === null ? null : BudgetMoneyResource::shape($calculation->projectedAvailableCentavos),
            'projected_status' => $calculation->projectedStatus?->value,
            'is_read_only' => $archived,
        ];
    }
}
