<?php

declare(strict_types=1);

namespace App\Data\Budgets;

use App\Enums\Budgets\BudgetStatus;

/**
 * Derived monthly values for one selected month. Nothing in here is persisted:
 * every figure is recalculated from authoritative transactions on each read.
 */
final readonly class BudgetMonthCalculation
{
    /** @param array<int, BudgetPlanCalculation> $plans */
    public function __construct(
        public int $year,
        public int $month,
        public string $from,
        public string $to,
        public int $totalPlannedCentavos,
        public int $budgetedRealizedCentavos,
        public int $actualAvailableCentavos,
        public ?float $overallUtilizationPercent,
        public BudgetStatus $overallStatus,
        public int $unbudgetedExpensesCentavos,
        public int $totalExpensesCentavos,
        public ?int $expectedCentavos,
        public ?int $projectedSpendingCentavos,
        public ?int $projectedAvailableCentavos,
        public ?BudgetStatus $projectedStatus,
        public array $plans,
    ) {}
}
