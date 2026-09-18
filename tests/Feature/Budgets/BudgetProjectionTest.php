<?php

declare(strict_types=1);

namespace Tests\Feature\Budgets;

use App\Models\FinancialAccount;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Budgets\BudgetFixtures;
use Tests\TestCase;

final class BudgetProjectionTest extends TestCase
{
    use BudgetFixtures;
    use RefreshDatabase;

    #[Test]
    public function current_and_future_months_expose_expected_and_projected_values(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense', ['name' => 'Food']);
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();
        $this->budgetExpense($user, $food, 55_000, '2026-09-05');
        $this->budgetExpense($user, $food, 20_000, '2026-09-25', ['status' => 'pending']);

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.plans.0.realized.amount_centavos', 55_000)
            ->assertJsonPath('data.budget.plans.0.expected.amount_centavos', 20_000)
            ->assertJsonPath('data.budget.plans.0.projected_spending.amount_centavos', 75_000)
            ->assertJsonPath('data.budget.plans.0.projected_available.amount_centavos', 25_000)
            ->assertJsonPath('data.budget.plans.0.projected_status', 'within')
            ->assertJsonPath('data.budget.plans.0.status', 'within')
            ->assertJsonPath('data.budget.summary.expected.amount_centavos', 20_000)
            ->assertJsonPath('data.budget.summary.projected_spending.amount_centavos', 75_000)
            ->assertJsonPath('data.budget.summary.projected_available.amount_centavos', 25_000)
            ->assertJsonPath('data.budget.summary.projected_status', 'within')
            ->assertJsonPath('data.budget.summary.budgeted_realized.amount_centavos', 55_000)
            ->assertJsonPath('data.budget.summary.actual_available.amount_centavos', 45_000);
    }

    #[Test]
    public function a_projection_can_exceed_the_plan_while_actual_spending_stays_within_it(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();
        $this->budgetExpense($user, $food, 40_000, '2026-09-05');
        $this->budgetExpense($user, $food, 80_000, '2026-09-26', ['status' => 'pending']);

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.plans.0.status', 'within')
            ->assertJsonPath('data.budget.plans.0.projected_status', 'exceeded')
            ->assertJsonPath('data.budget.plans.0.projected_available.amount_centavos', -20_000)
            ->assertJsonPath('data.budget.summary.overall_status', 'within')
            ->assertJsonPath('data.budget.summary.projected_status', 'exceeded');
    }

    #[Test]
    public function ended_months_omit_expected_and_projected_values_even_with_pending_expenses(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 8])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();
        $this->budgetExpense($user, $food, 30_000, '2026-08-05');
        $this->budgetExpense($user, $food, 25_000, '2026-08-20', ['status' => 'pending']);

        $this->getJson($this->budgetPath(2026, 8))
            ->assertOk()
            ->assertJsonPath('data.budget.plans.0.realized.amount_centavos', 30_000)
            ->assertJsonPath('data.budget.plans.0.expected', null)
            ->assertJsonPath('data.budget.plans.0.projected_spending', null)
            ->assertJsonPath('data.budget.plans.0.projected_available', null)
            ->assertJsonPath('data.budget.plans.0.projected_status', null)
            ->assertJsonPath('data.budget.summary.expected', null)
            ->assertJsonPath('data.budget.summary.projected_spending', null)
            ->assertJsonPath('data.budget.summary.projected_available', null)
            ->assertJsonPath('data.budget.summary.projected_status', null);
    }

    #[Test]
    public function zero_plan_months_omit_summary_projections_but_keep_zero_expected_reports(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $emptyId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->budgetExpense($user, $food, 15_000, '2026-09-20', ['status' => 'pending']);

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.summary.expected', null)
            ->assertJsonPath('data.budget.summary.projected_spending', null)
            ->assertJsonPath('data.budget.summary.unbudgeted_expenses.amount_centavos', 0);

        $this->postJson("/api/v1/budgets/{$emptyId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();
        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.summary.expected.amount_centavos', 15_000)
            ->assertJsonPath('data.budget.summary.projected_spending.amount_centavos', 15_000);
    }

    #[Test]
    public function unbudgeted_pending_expenses_stay_outside_the_monthly_projection(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $transport = $this->budgetCategory($user, 'expense');
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();

        $this->budgetExpense($user, $food, 10_000, '2026-09-08', ['status' => 'pending']);
        $this->budgetExpense($user, $transport, 99_000, '2026-09-09', ['status' => 'pending']);

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.summary.expected.amount_centavos', 10_000)
            ->assertJsonPath('data.budget.summary.projected_spending.amount_centavos', 10_000)
            ->assertJsonPath('data.budget.summary.projected_available.amount_centavos', 90_000);
    }

    #[Test]
    public function a_generated_pending_occurrence_qualifies_while_a_bare_schedule_does_not(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();
        $account = $this->budgetAccount($user);

        // A recurrence definition alone carries no expected spending.
        $recurrence = RecurringTransaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $food->id,
        ]);
        $this->getJson($this->budgetPath(2026, 9))->assertOk()->assertJsonPath('data.budget.summary.expected.amount_centavos', 0);

        // Its generated pending transaction does qualify.
        $this->budgetExpense($user, $food, 12_000, '2026-09-22', [
            'status' => 'pending',
            'recurring_transaction_id' => $recurrence->id,
            'recurrence_scheduled_date' => '2026-09-22',
        ]);

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.summary.expected.amount_centavos', 12_000)
            ->assertJsonPath('data.budget.summary.projected_spending.amount_centavos', 12_000);
    }

    #[Test]
    public function projections_never_change_balances_or_realized_values(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $account = $this->budgetAccount($user, ['current_balance_centavos' => 300_000]);
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();
        $this->budgetExpense($user, $food, 40_000, '2026-09-05');
        $this->budgetExpense($user, $food, 30_000, '2026-09-27', ['status' => 'pending']);
        $balanceBefore = (int) FinancialAccount::query()->findOrFail($account->id)->current_balance_centavos;
        $transactionCountBefore = Transaction::query()->where('user_id', $user->id)->count();

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.summary.projected_spending.amount_centavos', 70_000)
            ->assertJsonPath('data.budget.summary.budgeted_realized.amount_centavos', 40_000);

        self::assertSame($balanceBefore, (int) FinancialAccount::query()->findOrFail($account->id)->current_balance_centavos);
        self::assertSame($transactionCountBefore, Transaction::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function foreign_owners_pending_expenses_never_enter_the_projection(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();

        $other = User::factory()->create();
        $otherFood = $this->budgetCategory($other, 'expense');
        $this->budgetExpense($other, $otherFood, 75_000, '2026-09-28', ['status' => 'pending']);

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.summary.expected.amount_centavos', 0)
            ->assertJsonPath('data.budget.summary.projected_spending.amount_centavos', 0);
    }
}
