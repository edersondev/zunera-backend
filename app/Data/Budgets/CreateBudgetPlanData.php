<?php

declare(strict_types=1);

namespace App\Data\Budgets;

final readonly class CreateBudgetPlanData
{
    public function __construct(
        public int $budgetId,
        public int $categoryId,
        public int $plannedAmountCentavos,
    ) {}
}
