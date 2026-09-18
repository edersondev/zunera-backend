<?php

declare(strict_types=1);

namespace Tests\Feature\Budgets;

use App\Models\Category;
use App\Models\MonthlyBudget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Budgets\BudgetFixtures;
use Tests\TestCase;

final class BudgetFoundationTest extends TestCase
{
    use BudgetFixtures;
    use RefreshDatabase;

    #[Test]
    public function budget_routes_require_an_authenticated_owner(): void
    {
        $this->getJson($this->budgetPath(2026, 9))->assertUnauthorized();
        $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->assertUnauthorized();
    }

    #[Test]
    public function a_month_without_a_budget_returns_the_period_and_a_null_budget(): void
    {
        $user = $this->budgetSignIn();

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.period.year', 2026)
            ->assertJsonPath('data.period.month', 9)
            ->assertJsonPath('data.period.from', '2026-09-01')
            ->assertJsonPath('data.period.to', '2026-09-30')
            ->assertJsonPath('data.budget', null);

        self::assertSame(0, MonthlyBudget::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function an_owner_creates_an_empty_month_and_duplicates_are_rejected(): void
    {
        $user = $this->budgetSignIn();
        $account = $this->budgetAccount($user, ['current_balance_centavos' => 250_000]);
        $balanceBefore = (int) $account->fresh()->current_balance_centavos;

        $created = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])
            ->assertCreated()
            ->assertJsonPath('data.period.from', '2026-09-01')
            ->assertJsonPath('data.budget.summary.total_planned.amount_centavos', 0)
            ->assertJsonPath('data.budget.summary.budgeted_realized.amount_centavos', 0)
            ->assertJsonPath('data.budget.summary.actual_available.amount_centavos', 0)
            ->assertJsonPath('data.budget.summary.overall_utilization_percent', null)
            ->assertJsonPath('data.budget.summary.overall_status', 'not_applicable')
            ->assertJsonPath('data.budget.plans', []);

        $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])
            ->assertStatus(409)
            ->assertJsonPath('code', 'budget_month_conflict');

        $this->postJson('/api/v1/budgets', ['year' => 1850, 'month' => 13])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year', 'month']);

        self::assertSame($balanceBefore, $account->fresh()->current_balance_centavos ?? null);
        self::assertSame(0, Transaction::query()->where('user_id', $user->id)->count());

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.id', $created->json('data.budget.id'));
    }

    #[Test]
    public function plans_accept_only_available_active_expense_categories_with_positive_centavos(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $income = $this->budgetCategory($user, 'income');
        $archived = $this->budgetArchivedCategory($user, 'expense');
        $foreign = Category::factory()->create(['user_id' => User::factory()->create()->id]);
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->assertCreated()->json('data.budget.id');

        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])
            ->assertCreated()
            ->assertJsonPath('data.budget.plans.0.category.name', $food->name)
            ->assertJsonPath('data.budget.plans.0.planned.amount_centavos', 100_000)
            ->assertJsonPath('data.budget.plans.0.realized.amount_centavos', 0)
            ->assertJsonPath('data.budget.plans.0.available.amount_centavos', 100_000)
            ->assertJsonPath('data.budget.plans.0.utilization_percent', 0)
            ->assertJsonPath('data.budget.plans.0.status', 'within')
            ->assertJsonPath('data.budget.plans.0.excess.amount_centavos', 0)
            ->assertJsonPath('data.budget.plans.0.is_read_only', false);

        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 50_000])
            ->assertStatus(409)
            ->assertJsonPath('code', 'budget_plan_conflict');

        foreach ([$income->id, $archived->id] as $categoryId) {
            $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $categoryId, 'planned_amount_centavos' => 10_000])
                ->assertStatus(422)
                ->assertJsonPath('code', 'budget_category_unavailable');
        }

        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $foreign->id, 'planned_amount_centavos' => 10_000])
            ->assertNotFound();

        foreach ([0, -1, 10.999, 100_000_000_000] as $invalidAmount) {
            $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => $invalidAmount])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['planned_amount_centavos']);
        }
    }

    #[Test]
    public function foreign_budgets_and_unknown_plan_ids_are_not_found(): void
    {
        $this->budgetSignIn();
        $other = User::factory()->create();
        $foreignBudget = $this->budgetMonth($other, 2026, 9);

        $this->postJson("/api/v1/budgets/{$foreignBudget->id}/plans", ['category_id' => 1, 'planned_amount_centavos' => 1_000])
            ->assertNotFound();
        $this->patchJson("/api/v1/budgets/{$foreignBudget->id}/plans/1", ['planned_amount_centavos' => 1_000])
            ->assertNotFound();
        $this->deleteJson("/api/v1/budgets/{$foreignBudget->id}/plans/1")
            ->assertNotFound();
    }
}
