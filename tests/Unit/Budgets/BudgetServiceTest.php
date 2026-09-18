<?php

declare(strict_types=1);

namespace Tests\Unit\Budgets;

use App\Data\Budgets\CreateBudgetPlanData;
use App\Data\Budgets\UpdateBudgetPlanData;
use App\Enums\Categories\CategoryStatus;
use App\Exceptions\Budgets\BudgetStateException;
use App\Models\Category;
use App\Models\User;
use App\Services\Budgets\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Budgets\BudgetFixtures;
use Tests\TestCase;

final class BudgetServiceTest extends TestCase
{
    use BudgetFixtures;
    use RefreshDatabase;

    #[Test]
    public function only_active_available_expense_categories_can_receive_a_plan(): void
    {
        $this->freezeBudgetClock();
        $user = User::factory()->create();
        $service = app(BudgetService::class);
        $budget = $this->budgetMonth($user, 2026, 9);
        $income = $this->budgetCategory($user, 'income');
        $archived = $this->budgetArchivedCategory($user, 'expense');
        $foreign = Category::factory()->create(['user_id' => User::factory()->create()->id]);

        foreach ([$income, $archived] as $category) {
            try {
                $service->addPlan($user, $budget, new CreateBudgetPlanData((int) $budget->id, (int) $category->id, 10_000));
                self::fail('Expected an unavailable-category conflict.');
            } catch (BudgetStateException $exception) {
                self::assertSame('budget_category_unavailable', $exception->errorCode());
                self::assertSame(422, $exception->getCode());
            }
        }

        $this->expectException(NotFoundHttpException::class);
        $service->addPlan($user, $budget, new CreateBudgetPlanData((int) $budget->id, (int) $foreign->id, 10_000));
    }

    #[Test]
    public function a_plan_association_locks_the_category_classification_even_after_removal(): void
    {
        $this->freezeBudgetClock();
        $user = User::factory()->create();
        $service = app(BudgetService::class);
        $category = $this->budgetCategory($user, 'expense');
        $budget = $this->budgetMonth($user, 2026, 9);
        $plan = $service->addPlan($user, $budget, new CreateBudgetPlanData((int) $budget->id, (int) $category->id, 30_000));

        self::assertTrue((bool) $category->fresh()->has_budget_plans);

        $service->removePlan($user, $budget, (int) $plan->id);

        self::assertTrue((bool) $category->fresh()->has_budget_plans);
        self::assertSame(0, $budget->plans()->count());
    }

    #[Test]
    public function plans_for_archived_categories_are_read_only_until_restored(): void
    {
        $this->freezeBudgetClock();
        $user = User::factory()->create();
        $service = app(BudgetService::class);
        $category = $this->budgetCategory($user, 'expense');
        $budget = $this->budgetMonth($user, 2026, 9);
        $plan = $service->addPlan($user, $budget, new CreateBudgetPlanData((int) $budget->id, (int) $category->id, 30_000));

        $category->status = CategoryStatus::Archived;
        $category->archived_at = now();
        $category->save();

        try {
            $service->updatePlan($user, $budget, new UpdateBudgetPlanData((int) $budget->id, (int) $plan->id, 40_000));
            self::fail('Expected an archived-plan conflict.');
        } catch (BudgetStateException $exception) {
            self::assertSame('budget_plan_read_only', $exception->errorCode());
        }

        try {
            $service->removePlan($user, $budget, (int) $plan->id);
            self::fail('Expected an archived-plan conflict.');
        } catch (BudgetStateException $exception) {
            self::assertSame('budget_plan_read_only', $exception->errorCode());
        }

        $category->status = CategoryStatus::Active;
        $category->archived_at = null;
        $category->save();

        $service->updatePlan($user, $budget, new UpdateBudgetPlanData((int) $budget->id, (int) $plan->id, 40_000));
        self::assertSame(40_000, (int) $plan->fresh()->planned_amount_centavos);
    }

    #[Test]
    public function snapshots_preserve_the_identity_captured_at_plan_creation(): void
    {
        $this->freezeBudgetClock();
        $user = User::factory()->create();
        $service = app(BudgetService::class);
        $category = $this->budgetCategory($user, 'expense', ['name' => 'Food', 'color' => 'teal', 'icon' => 'circle']);
        $budget = $this->budgetMonth($user, 2026, 9);
        $plan = $service->addPlan($user, $budget, new CreateBudgetPlanData((int) $budget->id, (int) $category->id, 30_000));

        $category->name = 'Groceries';
        $category->color = 'blue';
        $category->save();

        $plan->refresh();
        self::assertSame('Food', $plan->category_name_snapshot);
        self::assertSame('teal', $plan->category_color_snapshot);
        self::assertSame('expense', $plan->category_classification_snapshot->value);
        self::assertSame('personal', $plan->category_origin_snapshot->value);
    }
}
