<?php

declare(strict_types=1);

namespace Tests\Feature\Budgets;

use App\Models\BudgetCategoryPlan;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Budgets\BudgetFixtures;
use Tests\TestCase;

final class BudgetFinancialIsolationTest extends TestCase
{
    use BudgetFixtures;
    use RefreshDatabase;

    #[Test]
    public function creating_changing_and_removing_plans_never_moves_money(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $account = $this->budgetAccount($user, ['current_balance_centavos' => 500_000]);
        $expense = $this->budgetExpense($user, $food, 80_000, '2026-09-06');
        $account->refresh();
        $balanceBefore = (int) $account->current_balance_centavos;
        $transactionCountBefore = Transaction::query()->where('user_id', $user->id)->count();
        $transactionUpdatedAt = $expense->fresh()->updated_at?->toIso8601String();
        $accountUpdatedAt = $account->updated_at?->toIso8601String();

        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->assertCreated()->json('data.budget.id');
        $planId = $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])
            ->assertCreated()
            ->assertJsonPath('data.budget.plans.0.realized.amount_centavos', 80_000)
            ->assertJsonPath('data.budget.plans.0.available.amount_centavos', 20_000)
            ->assertJsonPath('data.budget.summary.total_expenses.amount_centavos', 80_000)
            ->json('data.budget.plans.0.id');
        $this->patchJson("/api/v1/budgets/{$budgetId}/plans/{$planId}", ['planned_amount_centavos' => 150_000])
            ->assertOk()
            ->assertJsonPath('data.budget.plans.0.realized.amount_centavos', 80_000)
            ->assertJsonPath('data.budget.plans.0.available.amount_centavos', 70_000);
        $this->deleteJson("/api/v1/budgets/{$budgetId}/plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.budget.summary.unbudgeted_expenses.amount_centavos', 80_000)
            ->assertJsonPath('data.budget.summary.total_expenses.amount_centavos', 80_000)
            ->assertJsonPath('data.budget.summary.total_planned.amount_centavos', 0);

        self::assertSame($balanceBefore, (int) FinancialAccount::query()->findOrFail($account->id)->current_balance_centavos);
        self::assertSame($transactionCountBefore, Transaction::query()->where('user_id', $user->id)->count());
        self::assertSame($transactionUpdatedAt, $expense->fresh()->updated_at?->toIso8601String());
        self::assertSame($accountUpdatedAt, FinancialAccount::query()->findOrFail($account->id)->updated_at?->toIso8601String());
    }

    #[Test]
    public function reclassifying_a_linked_category_is_rejected_without_touching_the_plan(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();

        $this->patchJson("/api/v1/categories/{$food->id}", ['classification' => 'income'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'category_budget_plan_locked');

        self::assertSame('expense', $food->fresh()->classification->value);
        self::assertSame(100_000, (int) BudgetCategoryPlan::query()->where('monthly_budget_id', $budgetId)->value('planned_amount_centavos'));
    }
}
