<?php

declare(strict_types=1);

namespace Tests\Feature\Budgets;

use App\Models\MonthlyBudget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Budgets\BudgetFixtures;
use Tests\TestCase;

final class CopyMonthlyBudgetTest extends TestCase
{
    use BudgetFixtures;
    use RefreshDatabase;

    #[Test]
    public function copying_plans_into_an_empty_month_keeps_both_months_independent(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense', ['name' => 'Food']);
        $housing = $this->budgetCategory($user, 'expense', ['name' => 'Housing']);
        $sourceId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$sourceId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();
        $this->postJson("/api/v1/budgets/{$sourceId}/plans", ['category_id' => $housing->id, 'planned_amount_centavos' => 250_000])->assertCreated();
        $this->budgetExpense($user, $food, 80_000, '2026-09-14');

        $destinationId = $this->postJson("/api/v1/budgets/{$sourceId}/copy", ['destination_year' => 2026, 'destination_month' => 10])
            ->assertCreated()
            ->assertJsonPath('data.period.month', 10)
            ->assertJsonPath('data.budget.summary.total_planned.amount_centavos', 350_000)
            ->assertJsonPath('data.budget.summary.budgeted_realized.amount_centavos', 0)
            ->assertJsonPath('data.budget.summary.total_expenses.amount_centavos', 0)
            ->json('data.budget.id');

        // Later source edits never rewrite the copied destination.
        $sourcePlanId = (int) MonthlyBudget::query()->findOrFail($sourceId)->plans()->orderBy('id')->value('id');
        $this->patchJson("/api/v1/budgets/{$sourceId}/plans/{$sourcePlanId}", ['planned_amount_centavos' => 175_000])->assertOk();

        $this->getJson($this->budgetPath(2026, 10))
            ->assertOk()
            ->assertJsonPath('data.budget.id', $destinationId)
            ->assertJsonPath('data.budget.summary.total_planned.amount_centavos', 350_000);
        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.summary.budgeted_realized.amount_centavos', 80_000);
    }

    #[Test]
    public function copying_across_a_year_boundary_resolves_the_destination_month(): void
    {
        $user = $this->budgetSignIn('2026-12-20');
        $food = $this->budgetCategory($user, 'expense');
        $sourceId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 12])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$sourceId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 90_000])->assertCreated();

        $this->postJson("/api/v1/budgets/{$sourceId}/copy", ['destination_year' => 2027, 'destination_month' => 1])
            ->assertCreated()
            ->assertJsonPath('data.period.from', '2027-01-01')
            ->assertJsonPath('data.period.to', '2027-01-31')
            ->assertJsonPath('data.budget.summary.total_planned.amount_centavos', 90_000);
    }

    #[Test]
    public function copying_an_empty_source_creates_an_empty_destination(): void
    {
        $this->budgetSignIn();
        $sourceId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');

        $this->postJson("/api/v1/budgets/{$sourceId}/copy", ['destination_year' => 2026, 'destination_month' => 11])
            ->assertCreated()
            ->assertJsonPath('data.budget.plans', [])
            ->assertJsonPath('data.budget.summary.overall_status', 'not_applicable');
    }

    #[Test]
    public function an_occupied_or_identical_destination_is_rejected_without_changes(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $sourceId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$sourceId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();
        $occupiedId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 10])->json('data.budget.id');

        $this->postJson("/api/v1/budgets/{$sourceId}/copy", ['destination_year' => 2026, 'destination_month' => 9])
            ->assertStatus(422)
            ->assertJsonPath('code', 'budget_copy_same_month');
        $this->postJson("/api/v1/budgets/{$sourceId}/copy", ['destination_year' => 2026, 'destination_month' => 10])
            ->assertStatus(409)
            ->assertJsonPath('code', 'budget_copy_destination_occupied');

        self::assertSame(0, MonthlyBudget::query()->findOrFail($occupiedId)->plans()->count());
        self::assertSame(2, MonthlyBudget::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function a_source_plan_for_an_archived_category_blocks_the_whole_copy_atomically(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $archived = $this->budgetArchivedCategory($user, 'expense');
        $sourceId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$sourceId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();
        $this->budgetPlan(MonthlyBudget::query()->findOrFail($sourceId), $archived, 50_000);

        $this->postJson("/api/v1/budgets/{$sourceId}/copy", ['destination_year' => 2026, 'destination_month' => 10])
            ->assertStatus(409)
            ->assertJsonPath('code', 'budget_copy_archived_category');

        self::assertSame(1, MonthlyBudget::query()->where('user_id', $user->id)->count());
        $this->getJson($this->budgetPath(2026, 10))->assertOk()->assertJsonPath('data.budget', null);
    }

    #[Test]
    public function foreign_source_budgets_are_not_disclosed(): void
    {
        $this->budgetSignIn();
        $owner = User::factory()->create();
        $category = $this->budgetCategory($owner, 'expense');
        $source = $this->budgetMonth($owner, 2026, 9);
        $this->budgetPlan($source, $category, 40_000);

        $this->postJson("/api/v1/budgets/{$source->id}/copy", ['destination_year' => 2026, 'destination_month' => 10])
            ->assertNotFound();
        self::assertSame(1, MonthlyBudget::query()->count());
    }
}
