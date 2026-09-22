<?php

declare(strict_types=1);

namespace App\Services\Budgets;

use App\Data\Budgets\BudgetMonthCalculation;
use App\Data\Budgets\BudgetPlanCalculation;
use App\Enums\Budgets\BudgetStatus;
use App\Enums\Transactions\TransactionStatus;
use App\Enums\Transactions\TransactionType;
use App\Models\BudgetCategoryPlan;
use App\Models\MonthlyBudget;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CreditCards\CreditCardBudgetProjectionService;
use Illuminate\Support\Collection;

/**
 * Derives every budget figure from authoritative transactions at read time.
 * Effective, non-removed expense transactions consume a plan; income, own
 * account transfers, pending and removed records never do.
 */
final class BudgetCalculationService
{
    public function __construct(
        private readonly BudgetMonthResolver $resolver,
        private readonly CreditCardBudgetProjectionService $creditCards,
    ) {}

    public function forMonth(User $user, MonthlyBudget $budget): BudgetMonthCalculation
    {
        $year = (int) $budget->budget_year;
        $month = (int) $budget->budget_month;
        $from = $this->resolver->from($year, $month);
        $to = $this->resolver->to($year, $month);
        $effective = $this->mergeCategoryTotals(
            $this->effectiveExpensesByCategory($user, $from, $to),
            $this->creditCards->realizedByCategory($user, $from, $to),
        );
        $endedMonth = $this->resolver->isEnded($year, $month);
        // Past months never advertise future expectations; realized spending
        // stays the only truth once the month is over.
        $pending = $endedMonth
            ? collect()
            : $this->mergeCategoryTotals(
                $this->pendingExpensesByCategory($user, $from, $to),
                $this->creditCards->expectedByCategory($user, $from, $to),
            );
        $plans = $budget->plans()->with('category')->orderBy('id')->get();
        $planCalculations = [];
        $totalPlanned = 0;
        $budgetedRealized = 0;
        $summaryExpected = 0;
        $hasExpectedInput = false;
        $plannedCategoryIds = [];

        foreach ($plans as $plan) {
            $calculation = $this->planCalculation($plan, $effective, $pending, $endedMonth);
            $planCalculations[$calculation->planId] = $calculation;
            $totalPlanned += $calculation->plannedCentavos;
            $budgetedRealized += $calculation->realizedCentavos;
            $plannedCategoryIds[] = $calculation->categoryId;

            if ($calculation->expectedCentavos !== null) {
                $summaryExpected += $calculation->expectedCentavos;
                $hasExpectedInput = true;
            }
        }

        $unbudgeted = $this->sumExcluding($effective, $plannedCategoryIds);
        $hasPlans = $planCalculations !== [];

        $projectionAvailable = $hasPlans && ! $endedMonth;
        $projectedSpending = $projectionAvailable ? $budgetedRealized + $summaryExpected : null;

        return new BudgetMonthCalculation(
            $year,
            $month,
            $from,
            $to,
            $totalPlanned,
            $budgetedRealized,
            $totalPlanned - $budgetedRealized,
            $hasPlans ? $this->utilizationPercent($budgetedRealized, $totalPlanned) : null,
            $hasPlans ? BudgetStatus::fromAmounts($budgetedRealized, $totalPlanned) : BudgetStatus::NotApplicable,
            $unbudgeted,
            $budgetedRealized + $unbudgeted,
            $projectionAvailable ? $summaryExpected : null,
            $projectedSpending,
            $projectedSpending === null ? null : $totalPlanned - $projectedSpending,
            $projectedSpending === null ? null : BudgetStatus::fromAmounts($projectedSpending, $totalPlanned),
            $planCalculations,
        );
    }

    /** @return Collection<int, int> */
    private function effectiveExpensesByCategory(User $user, string $from, string $to): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', TransactionType::Expense)
            ->where('status', TransactionStatus::Effective)
            ->whereNull('removed_at')
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->groupBy('category_id')
            ->selectRaw('category_id, SUM(amount_centavos) as total_centavos')
            ->pluck('total_centavos', 'category_id')
            ->map(static fn (mixed $total): int => (int) $total);
    }

    /**
     * Pending expense transactions only. A recurrence definition without a
     * generated pending transaction never counts as expected spending.
     *
     * @return Collection<int, int>
     */
    private function pendingExpensesByCategory(User $user, string $from, string $to): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', TransactionType::Expense)
            ->where('status', TransactionStatus::Pending)
            ->whereNull('removed_at')
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->groupBy('category_id')
            ->selectRaw('category_id, SUM(amount_centavos) as total_centavos')
            ->pluck('total_centavos', 'category_id')
            ->map(static fn (mixed $total): int => (int) $total);
    }

    /** @param Collection<int, int> $effective @param Collection<int, int> $pending */
    private function planCalculation(BudgetCategoryPlan $plan, Collection $effective, Collection $pending, bool $endedMonth): BudgetPlanCalculation
    {
        $planned = (int) $plan->planned_amount_centavos;
        $realized = (int) ($effective[(int) $plan->category_id] ?? 0);
        $expected = $endedMonth ? null : (int) ($pending[(int) $plan->category_id] ?? 0);
        $projectedSpending = $expected === null ? null : $realized + $expected;

        return new BudgetPlanCalculation(
            (int) $plan->id,
            (int) $plan->category_id,
            $planned,
            $realized,
            $planned - $realized,
            $this->utilizationPercent($realized, $planned),
            BudgetStatus::fromAmounts($realized, $planned),
            max(0, $realized - $planned),
            $expected,
            $projectedSpending,
            $projectedSpending === null ? null : $planned - $projectedSpending,
            $projectedSpending === null ? null : BudgetStatus::fromAmounts($projectedSpending, $planned),
        );
    }

    /** @param Collection<int, int> $effective @param list<int> $excludedCategoryIds */
    private function sumExcluding(Collection $effective, array $excludedCategoryIds): int
    {
        return (int) $effective
            ->reject(static fn (int $total, int $categoryId): bool => in_array($categoryId, $excludedCategoryIds, true))
            ->sum();
    }

    private function utilizationPercent(int $spentCentavos, int $plannedCentavos): float
    {
        if ($plannedCentavos <= 0) {
            return 0.0;
        }

        return round($spentCentavos / $plannedCentavos * 100, 2);
    }

    /**
     * Card installments join ordinary transactions as a recognized expense
     * source; both sides stay in exact centavos and are summed per category.
     *
     * @param  Collection<int, int>  $base
     * @param  Collection<int, int>  $cardTotals
     * @return Collection<int, int>
     */
    private function mergeCategoryTotals(Collection $base, Collection $cardTotals): Collection
    {
        foreach ($cardTotals as $categoryId => $total) {
            $categoryId = (int) $categoryId;
            $base[$categoryId] = (int) ($base[$categoryId] ?? 0) + (int) $total;
        }

        return $base;
    }
}
