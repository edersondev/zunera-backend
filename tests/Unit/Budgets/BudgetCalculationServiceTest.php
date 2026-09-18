<?php

declare(strict_types=1);

namespace Tests\Unit\Budgets;

use App\Enums\Budgets\BudgetStatus;
use App\Models\User;
use App\Services\Budgets\BudgetCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Budgets\BudgetFixtures;
use Tests\TestCase;

final class BudgetCalculationServiceTest extends TestCase
{
    use BudgetFixtures;
    use RefreshDatabase;

    #[Test]
    public function realized_values_use_only_effective_non_removed_expenses_in_the_full_month(): void
    {
        $this->freezeBudgetClock();
        $user = User::factory()->create();
        $food = $this->budgetCategory($user, 'expense');
        $income = $this->budgetCategory($user, 'income');
        $budget = $this->budgetMonth($user, 2026, 9);
        $this->budgetPlan($budget, $food, 100_000);

        $this->budgetExpense($user, $food, 30_000, '2026-09-01');
        $this->budgetExpense($user, $food, 42_000, '2026-09-30');
        $this->budgetExpense($user, $food, 10_000, '2026-08-31');
        $this->budgetExpense($user, $food, 55_000, '2026-09-15', ['removed_at' => now()]);
        $this->budgetExpense($user, $food, 66_000, '2026-09-15', ['status' => 'pending']);
        $this->budgetTransaction($user, 'income', ['category_id' => $income->id, 'amount_centavos' => 99_000, 'transaction_date' => '2026-09-10']);

        $calculation = app(BudgetCalculationService::class)->forMonth($user, $budget);
        $plan = $calculation->plans[(int) $budget->plans()->firstOrFail()->id];

        self::assertSame(72_000, $plan->realizedCentavos);
        self::assertSame(28_000, $plan->availableCentavos);
        self::assertSame(72.0, $plan->utilizationPercent);
        self::assertSame(BudgetStatus::Within, $plan->status);
        self::assertSame(0, $plan->excessCentavos);
        self::assertSame(72_000, $calculation->budgetedRealizedCentavos);
        self::assertSame(0, $calculation->unbudgetedExpensesCentavos);
        self::assertSame(72_000, $calculation->totalExpensesCentavos);
    }

    #[Test]
    public function unbudgeted_and_total_expenses_stay_separate_from_budgeted_values(): void
    {
        $this->freezeBudgetClock();
        $user = User::factory()->create();
        $food = $this->budgetCategory($user, 'expense');
        $transport = $this->budgetCategory($user, 'expense');
        $budget = $this->budgetMonth($user, 2026, 9);
        $this->budgetPlan($budget, $food, 100_000);

        $this->budgetExpense($user, $food, 40_000, '2026-09-05');
        $this->budgetExpense($user, $transport, 15_000, '2026-09-06');
        $this->budgetExpense($user, $transport, 5_000, '2026-09-07', ['status' => 'pending']);

        $calculation = app(BudgetCalculationService::class)->forMonth($user, $budget);

        self::assertSame(40_000, $calculation->budgetedRealizedCentavos);
        self::assertSame(15_000, $calculation->unbudgetedExpensesCentavos);
        self::assertSame(55_000, $calculation->totalExpensesCentavos);
    }

    #[Test]
    public function status_thresholds_follow_exact_centavos(): void
    {
        self::assertSame(BudgetStatus::Within, BudgetStatus::fromAmounts(79_999, 100_000));
        self::assertSame(BudgetStatus::Approaching, BudgetStatus::fromAmounts(80_000, 100_000));
        self::assertSame(BudgetStatus::Approaching, BudgetStatus::fromAmounts(99_999, 100_000));
        self::assertSame(BudgetStatus::Reached, BudgetStatus::fromAmounts(100_000, 100_000));
        self::assertSame(BudgetStatus::Exceeded, BudgetStatus::fromAmounts(100_001, 100_000));
    }

    #[Test]
    public function a_budget_without_plans_reports_zero_money_and_not_applicable_status(): void
    {
        $this->freezeBudgetClock();
        $user = User::factory()->create();
        $food = $this->budgetCategory($user, 'expense');
        $budget = $this->budgetMonth($user, 2026, 9);
        $this->budgetExpense($user, $food, 25_000, '2026-09-05');

        $calculation = app(BudgetCalculationService::class)->forMonth($user, $budget);

        self::assertSame(0, $calculation->totalPlannedCentavos);
        self::assertSame(0, $calculation->budgetedRealizedCentavos);
        self::assertSame(0, $calculation->actualAvailableCentavos);
        self::assertNull($calculation->overallUtilizationPercent);
        self::assertSame(BudgetStatus::NotApplicable, $calculation->overallStatus);
        self::assertSame(25_000, $calculation->unbudgetedExpensesCentavos);
        self::assertSame(25_000, $calculation->totalExpensesCentavos);
        self::assertSame([], $calculation->plans);
    }

    #[Test]
    public function pending_removed_income_and_transfer_records_never_consume_a_plan(): void
    {
        $this->freezeBudgetClock();
        $user = User::factory()->create();
        $food = $this->budgetCategory($user, 'expense');
        $income = $this->budgetCategory($user, 'income');
        $budget = $this->budgetMonth($user, 2026, 9);
        $this->budgetPlan($budget, $food, 100_000);

        $this->budgetExpense($user, $food, 20_000, '2026-09-03', ['status' => 'pending']);
        $this->budgetExpense($user, $food, 30_000, '2026-09-04', ['removed_at' => now()]);
        $this->budgetTransaction($user, 'income', ['category_id' => $income->id, 'amount_centavos' => 60_000, 'transaction_date' => '2026-09-05']);
        $this->budgetTransfer($user, ['amount_centavos' => 70_000, 'transfer_date' => '2026-09-06']);
        $this->budgetExpense($user, $food, 15_000, '2026-09-07');

        $calculation = app(BudgetCalculationService::class)->forMonth($user, $budget);
        $plan = $calculation->plans[(int) $budget->plans()->firstOrFail()->id];

        self::assertSame(15_000, $plan->realizedCentavos);
        self::assertSame(15_000, $calculation->budgetedRealizedCentavos);
        self::assertSame(0, $calculation->unbudgetedExpensesCentavos);
    }

    #[Test]
    public function negative_availability_reports_excess_for_the_plan_and_the_summary(): void
    {
        $this->freezeBudgetClock();
        $user = User::factory()->create();
        $food = $this->budgetCategory($user, 'expense');
        $budget = $this->budgetMonth($user, 2026, 9);
        $this->budgetPlan($budget, $food, 50_000);
        $this->budgetExpense($user, $food, 63_500, '2026-09-12');

        $calculation = app(BudgetCalculationService::class)->forMonth($user, $budget);
        $plan = $calculation->plans[(int) $budget->plans()->firstOrFail()->id];

        self::assertSame(-13_500, $plan->availableCentavos);
        self::assertSame(13_500, $plan->excessCentavos);
        self::assertSame(BudgetStatus::Exceeded, $plan->status);
        self::assertSame(127.0, $plan->utilizationPercent);
        self::assertSame(BudgetStatus::Exceeded, $calculation->overallStatus);
        self::assertSame(-13_500, $calculation->actualAvailableCentavos);
    }

    #[Test]
    public function expected_spending_uses_pending_expenses_only_for_current_or_future_months(): void
    {
        $this->freezeBudgetClock();
        $user = User::factory()->create();
        $food = $this->budgetCategory($user, 'expense');
        $transport = $this->budgetCategory($user, 'expense');
        $current = $this->budgetMonth($user, 2026, 9);
        $past = $this->budgetMonth($user, 2026, 8);
        $this->budgetPlan($current, $food, 100_000);
        $this->budgetPlan($past, $food, 100_000);

        $this->budgetExpense($user, $food, 20_000, '2026-09-28', ['status' => 'pending']);
        $this->budgetExpense($user, $transport, 30_000, '2026-09-29', ['status' => 'pending']);
        $this->budgetExpense($user, $food, 40_000, '2026-08-20', ['status' => 'pending']);

        $currentCalculation = app(BudgetCalculationService::class)->forMonth($user, $current);
        $currentPlan = $currentCalculation->plans[(int) $current->plans()->firstOrFail()->id];
        self::assertSame(20_000, $currentPlan->expectedCentavos);
        self::assertSame(20_000, $currentPlan->projectedSpendingCentavos);
        self::assertSame(80_000, $currentPlan->projectedAvailableCentavos);
        // Pending expenses in unbudgeted categories stay outside the projection.
        self::assertSame(20_000, $currentCalculation->expectedCentavos);
        self::assertSame(0, $currentCalculation->budgetedRealizedCentavos);
        self::assertSame(20_000, $currentCalculation->projectedSpendingCentavos);

        $pastCalculation = app(BudgetCalculationService::class)->forMonth($user, $past);
        $pastPlan = $pastCalculation->plans[(int) $past->plans()->firstOrFail()->id];
        self::assertNull($pastPlan->expectedCentavos);
        self::assertNull($pastPlan->projectedSpendingCentavos);
        self::assertNull($pastCalculation->expectedCentavos);
        self::assertNull($pastCalculation->projectedStatus);
    }

    #[Test]
    public function a_zero_plan_month_omits_summary_projections(): void
    {
        $this->freezeBudgetClock();
        $user = User::factory()->create();
        $food = $this->budgetCategory($user, 'expense');
        $budget = $this->budgetMonth($user, 2026, 9);
        $this->budgetExpense($user, $food, 30_000, '2026-09-28', ['status' => 'pending']);

        $calculation = app(BudgetCalculationService::class)->forMonth($user, $budget);

        self::assertNull($calculation->expectedCentavos);
        self::assertNull($calculation->projectedSpendingCentavos);
        self::assertNull($calculation->projectedAvailableCentavos);
        self::assertNull($calculation->projectedStatus);
    }
}
