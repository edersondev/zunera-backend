<?php

declare(strict_types=1);

namespace App\Data\Budgets;

final readonly class UpdateBudgetPlanData
{
    public function __construct(
        public int $budgetId,
        public int $planId,
        public int $plannedAmountCentavos,
    ) {}
}
