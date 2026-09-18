<?php

declare(strict_types=1);

namespace Tests\Feature\Budgets;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Budgets\BudgetFixtures;
use Tests\TestCase;

final class ViewBudgetSpendingTest extends TestCase
{
    use BudgetFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_month_read_exposes_planned_realized_available_utilization_and_status(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense', ['name' => 'Food']);
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();
        $this->budgetExpense($user, $food, 72_000, '2026-09-14');

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.plans.0.planned.amount_centavos', 100_000)
            ->assertJsonPath('data.budget.plans.0.realized.amount_centavos', 72_000)
            ->assertJsonPath('data.budget.plans.0.available.amount_centavos', 28_000)
            ->assertJsonPath('data.budget.plans.0.utilization_percent', 72)
            ->assertJsonPath('data.budget.plans.0.status', 'within')
            ->assertJsonPath('data.budget.plans.0.excess.amount_centavos', 0)
            ->assertJsonPath('data.budget.plans.0.category.name', 'Food')
            ->assertJsonPath('data.budget.plans.0.is_read_only', false)
            ->assertJsonPath('data.budget.summary.total_planned.amount_centavos', 100_000)
            ->assertJsonPath('data.budget.summary.budgeted_realized.amount_centavos', 72_000)
            ->assertJsonPath('data.budget.summary.actual_available.amount_centavos', 28_000)
            ->assertJsonPath('data.budget.summary.overall_utilization_percent', 72)
            ->assertJsonPath('data.budget.summary.overall_status', 'within')
            ->assertJsonPath('data.budget.summary.unbudgeted_expenses.amount_centavos', 0)
            ->assertJsonPath('data.budget.summary.total_expenses.amount_centavos', 72_000);
    }

    #[Test]
    public function exceeded_plans_report_excess_instead_of_only_a_negative_available(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();
        $this->budgetExpense($user, $food, 107_000, '2026-09-10');

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.plans.0.available.amount_centavos', -7_000)
            ->assertJsonPath('data.budget.plans.0.excess.amount_centavos', 7_000)
            ->assertJsonPath('data.budget.plans.0.status', 'exceeded')
            ->assertJsonPath('data.budget.summary.overall_status', 'exceeded')
            ->assertJsonPath('data.budget.summary.actual_available.amount_centavos', -7_000);
    }

    #[Test]
    public function unbudgeted_expenses_stay_separate_and_totals_stay_complete(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $transport = $this->budgetCategory($user, 'expense');
        $income = $this->budgetCategory($user, 'income');
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();

        $this->budgetExpense($user, $food, 40_000, '2026-09-05');
        $this->budgetExpense($user, $transport, 12_500, '2026-09-06');
        $this->budgetExpense($user, $transport, 9_000, '2026-09-07', ['status' => 'pending']);
        $this->budgetExpense($user, $transport, 8_000, '2026-09-08', ['removed_at' => now()]);
        $this->budgetTransaction($user, 'income', ['category_id' => $income->id, 'amount_centavos' => 500_000, 'transaction_date' => '2026-09-09']);
        $this->budgetTransfer($user, ['amount_centavos' => 70_000, 'transfer_date' => '2026-09-10']);

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.summary.budgeted_realized.amount_centavos', 40_000)
            ->assertJsonPath('data.budget.summary.unbudgeted_expenses.amount_centavos', 12_500)
            ->assertJsonPath('data.budget.summary.total_expenses.amount_centavos', 52_500);
    }

    #[Test]
    public function a_transaction_correction_restates_the_next_read_without_stale_values(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();
        $expense = $this->budgetExpense($user, $food, 30_000, '2026-09-05');

        $this->getJson($this->budgetPath(2026, 9))->assertOk()->assertJsonPath('data.budget.plans.0.realized.amount_centavos', 30_000);

        $expense->forceFill(['amount_centavos' => 45_000])->save();
        $this->getJson($this->budgetPath(2026, 9))->assertOk()->assertJsonPath('data.budget.plans.0.realized.amount_centavos', 45_000);

        $expense->forceFill(['transaction_date' => '2026-10-02'])->save();
        $this->getJson($this->budgetPath(2026, 9))->assertOk()->assertJsonPath('data.budget.plans.0.realized.amount_centavos', 0);
        $this->getJson($this->budgetPath(2026, 10))->assertOk()->assertJsonPath('data.budget', null);

        $expense->forceFill(['status' => 'pending'])->save();
        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.plans.0.realized.amount_centavos', 0)
            ->assertJsonPath('data.budget.summary.total_expenses.amount_centavos', 0);

        self::assertSame(1, Transaction::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function another_owners_spending_never_enters_the_selected_month(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();

        $other = User::factory()->create();
        $otherFood = $this->budgetCategory($other, 'expense');
        $this->budgetExpense($other, $otherFood, 90_000, '2026-09-12');

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.plans.0.realized.amount_centavos', 0)
            ->assertJsonPath('data.budget.summary.total_expenses.amount_centavos', 0);
    }
}
