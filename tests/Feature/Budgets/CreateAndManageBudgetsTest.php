<?php

declare(strict_types=1);

namespace Tests\Feature\Budgets;

use App\Models\BudgetCategoryPlan;
use App\Models\MonthlyBudget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Budgets\BudgetFixtures;
use Tests\TestCase;

final class CreateAndManageBudgetsTest extends TestCase
{
    use BudgetFixtures;
    use RefreshDatabase;

    #[Test]
    public function an_owner_creates_a_month_and_manages_category_plans(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense', ['name' => 'Food']);
        $housing = $this->budgetCategory($user, 'expense', ['name' => 'Housing']);

        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])
            ->assertCreated()
            ->assertJsonPath('data.budget.plans', [])
            ->json('data.budget.id');

        $foodPlanId = $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])
            ->assertCreated()
            ->json('data.budget.plans.0.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $housing->id, 'planned_amount_centavos' => 250_000])->assertCreated();

        $updated = $this->patchJson("/api/v1/budgets/{$budgetId}/plans/{$foodPlanId}", ['planned_amount_centavos' => 120_000])
            ->assertOk()
            ->json('data.budget.plans');
        self::assertCount(2, $updated);
        self::assertSame(120_000, collect($updated)->firstWhere('id', $foodPlanId)['planned']['amount_centavos']);
        self::assertSame(370_000, $updated[0]['planned']['amount_centavos'] + $updated[1]['planned']['amount_centavos']);

        $this->deleteJson("/api/v1/budgets/{$budgetId}/plans/{$foodPlanId}")->assertOk();
        $remaining = MonthlyBudget::query()->findOrFail($budgetId)->plans()->get();
        self::assertCount(1, $remaining);
        self::assertSame((int) $housing->id, (int) $remaining->first()->category_id);
    }

    #[Test]
    public function plans_are_unique_per_category_and_money_bounds_are_enforced(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();

        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])
            ->assertStatus(409)
            ->assertJsonPath('code', 'budget_plan_conflict');
        self::assertSame(1, BudgetCategoryPlan::query()->where('monthly_budget_id', $budgetId)->count());

        $planId = (int) BudgetCategoryPlan::query()->where('monthly_budget_id', $budgetId)->value('id');

        foreach ([0, -1, 10.999, 100_000_000_000] as $amount) {
            $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => $amount])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['planned_amount_centavos']);
        }

        foreach ([0, -5, 10.999, 100_000_000_000, 'abc'] as $amount) {
            $this->patchJson("/api/v1/budgets/{$budgetId}/plans/{$planId}", ['planned_amount_centavos' => $amount])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['planned_amount_centavos']);
        }

        self::assertSame(100_000, (int) BudgetCategoryPlan::query()->findOrFail($planId)->planned_amount_centavos);

        $this->patchJson("/api/v1/budgets/{$budgetId}/plans/999999", ['planned_amount_centavos' => 1_000])->assertNotFound();
        $this->deleteJson("/api/v1/budgets/{$budgetId}/plans/999999")->assertNotFound();
    }

    #[Test]
    public function another_owners_budget_and_plans_stay_unreachable(): void
    {
        $this->budgetSignIn();
        $owner = User::factory()->create();
        $category = $this->budgetCategory($owner, 'expense');
        $budget = $this->budgetMonth($owner, 2026, 9);
        $plan = $this->budgetPlan($budget, $category, 50_000);

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget', null);
        $this->patchJson("/api/v1/budgets/{$budget->id}/plans/{$plan->id}", ['planned_amount_centavos' => 10_000])->assertNotFound();
        $this->deleteJson("/api/v1/budgets/{$budget->id}/plans/{$plan->id}")->assertNotFound();
        $this->postJson("/api/v1/budgets/{$budget->id}/plans", ['category_id' => $category->id, 'planned_amount_centavos' => 10_000])->assertNotFound();

        self::assertSame(50_000, (int) $plan->fresh()->planned_amount_centavos);
    }

    #[Test]
    public function month_creation_rejects_invalid_and_out_of_range_periods(): void
    {
        $this->budgetSignIn();

        $this->postJson('/api/v1/budgets', ['year' => '2026', 'month' => '9'])->assertCreated();
        $this->postJson('/api/v1/budgets', ['year' => 2027, 'month' => 0])->assertUnprocessable()->assertJsonValidationErrors(['month']);
        $this->postJson('/api/v1/budgets', ['year' => 2101, 'month' => 13])->assertUnprocessable()->assertJsonValidationErrors(['year', 'month']);
        $this->getJson($this->budgetPath(2026, 13))->assertUnprocessable()->assertJsonValidationErrors(['month']);
        $this->getJson($this->budgetPath(1800, 1))->assertUnprocessable()->assertJsonValidationErrors(['year']);
    }
}
