<?php

declare(strict_types=1);

namespace Tests\Feature\Budgets;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Budgets\BudgetFixtures;
use Tests\TestCase;

final class ArchivedBudgetCategoryTest extends TestCase
{
    use BudgetFixtures;
    use RefreshDatabase;

    #[Test]
    public function an_archived_category_keeps_its_snapshot_identity_and_stays_read_only(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense', ['name' => 'Food', 'color' => 'teal', 'icon' => 'circle']);
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $planId = $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->json('data.budget.plans.0.id');
        $this->budgetExpense($user, $food, 30_000, '2026-09-09');

        // Archive through the owner-facing route so the lifecycle stays real.
        $this->postJson("/api/v1/categories/{$food->id}/archive")->assertOk();

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.plans.0.category.name', 'Food')
            ->assertJsonPath('data.budget.plans.0.category.status', 'archived')
            ->assertJsonPath('data.budget.plans.0.category.color', 'teal')
            ->assertJsonPath('data.budget.plans.0.is_read_only', true)
            ->assertJsonPath('data.budget.plans.0.planned.amount_centavos', 100_000)
            ->assertJsonPath('data.budget.plans.0.realized.amount_centavos', 30_000)
            ->assertJsonPath('data.budget.plans.0.available.amount_centavos', 70_000)
            ->assertJsonPath('data.budget.plans.0.status', 'within');

        $this->patchJson("/api/v1/budgets/{$budgetId}/plans/{$planId}", ['planned_amount_centavos' => 120_000])
            ->assertStatus(409)
            ->assertJsonPath('code', 'budget_plan_read_only');
        $this->deleteJson("/api/v1/budgets/{$budgetId}/plans/{$planId}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'budget_plan_read_only');

        // A restored category becomes mutable again through the category routes.
        $this->postJson("/api/v1/categories/{$food->id}/restore")->assertOk();

        $this->patchJson("/api/v1/budgets/{$budgetId}/plans/{$planId}", ['planned_amount_centavos' => 120_000])
            ->assertOk()
            ->assertJsonPath('data.budget.plans.0.planned.amount_centavos', 120_000)
            ->assertJsonPath('data.budget.plans.0.is_read_only', false);
    }

    #[Test]
    public function historical_budgets_keep_the_snapshot_name_after_a_rename(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense', ['name' => 'Food']);
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->assertCreated();

        $this->patchJson("/api/v1/categories/{$food->id}", ['name' => 'Groceries'])->assertOk();

        $this->getJson($this->budgetPath(2026, 9))
            ->assertOk()
            ->assertJsonPath('data.budget.plans.0.category.name', 'Food')
            ->assertJsonPath('data.budget.plans.0.category.status', 'active');

        self::assertSame('Groceries', Category::query()->findOrFail($food->id)->name);
    }

    #[Test]
    public function a_plan_linked_category_keeps_its_classification_lock_after_archive_restore_cycles(): void
    {
        $user = $this->budgetSignIn();
        $food = $this->budgetCategory($user, 'expense');
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $planId = $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 100_000])->json('data.budget.plans.0.id');

        $this->postJson("/api/v1/categories/{$food->id}/archive")->assertOk();
        $this->postJson("/api/v1/categories/{$food->id}/restore")->assertOk();
        $this->deleteJson("/api/v1/budgets/{$budgetId}/plans/{$planId}")->assertOk();

        $this->patchJson("/api/v1/categories/{$food->id}", ['classification' => 'income'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'category_budget_plan_locked');
        self::assertSame('expense', $food->fresh()->classification->value);
    }
}
