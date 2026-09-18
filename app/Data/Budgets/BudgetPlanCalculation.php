<?php

declare(strict_types=1);

namespace App\Data\Budgets;

use App\Enums\Budgets\BudgetStatus;

/**
 * Read-time values for one category plan. Projection fields stay null when the
 * budget month already ended; they never replace actual values.
 */
final readonly class BudgetPlanCalculation
{
    public function __construct(
        public int $planId,
        public int $categoryId,
        public int $plannedCentavos,
        public int $realizedCentavos,
        public int $availableCentavos,
        public float $utilizationPercent,
        public BudgetStatus $status,
        public int $excessCentavos,
        public ?int $expectedCentavos = null,
        public ?int $projectedSpendingCentavos = null,
        public ?int $projectedAvailableCentavos = null,
        public ?BudgetStatus $projectedStatus = null,
    ) {}
}
