<?php

declare(strict_types=1);

namespace App\Services\Budgets;

use App\Data\Budgets\BudgetMonthData;
use App\Data\Budgets\CopyBudgetData;
use App\Data\Budgets\CreateBudgetPlanData;
use App\Data\Budgets\UpdateBudgetPlanData;
use App\Enums\Categories\CategoryClassification;
use App\Enums\Categories\CategoryStatus;
use App\Exceptions\Budgets\BudgetStateException;
use App\Models\BudgetCategoryPlan;
use App\Models\Category;
use App\Models\MonthlyBudget;
use App\Models\User;
use App\Services\Categories\CategoryService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Owns monthly budget mutations and their safety rules. Nothing here touches
 * transactions, transfers or account balances: budgets are planning data only.
 */
final class BudgetService
{
    public function __construct(private readonly CategoryService $categories) {}

    public function findOwnedMonth(User $user, int $year, int $month): ?MonthlyBudget
    {
        return MonthlyBudget::query()
            ->where('user_id', $user->id)
            ->where('budget_year', $year)
            ->where('budget_month', $month)
            ->first();
    }

    public function requireOwnedMonth(User $user, int $year, int $month): MonthlyBudget
    {
        $budget = $this->findOwnedMonth($user, $year, $month);

        if (! $budget instanceof MonthlyBudget) {
            throw new NotFoundHttpException('Monthly budget not found or not accessible to the signed-in user.');
        }

        return $budget;
    }

    public function requireOwnedBudget(User $user, int $budgetId): MonthlyBudget
    {
        $budget = MonthlyBudget::query()->where('id', $budgetId)->where('user_id', $user->id)->first();

        if (! $budget instanceof MonthlyBudget) {
            throw new NotFoundHttpException('Monthly budget not found or not accessible to the signed-in user.');
        }

        return $budget;
    }

    public function createMonth(BudgetMonthData $data): MonthlyBudget
    {
        try {
            return DB::transaction(fn (): MonthlyBudget => MonthlyBudget::query()->create([
                'user_id' => $data->userId,
                'budget_year' => $data->year,
                'budget_month' => $data->month,
            ]));
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw BudgetStateException::monthAlreadyBudgeted();
            }

            throw $exception;
        }
    }

    public function addPlan(User $user, MonthlyBudget $budget, CreateBudgetPlanData $data): BudgetCategoryPlan
    {
        $category = $this->eligibleExpenseCategory($user, $data->categoryId);

        try {
            return DB::transaction(function () use ($budget, $category, $data): BudgetCategoryPlan {
                $plan = BudgetCategoryPlan::query()->create([
                    'monthly_budget_id' => $budget->id,
                    'category_id' => $category->id,
                    'planned_amount_centavos' => $data->plannedAmountCentavos,
                    'currency_code' => 'BRL',
                    'category_name_snapshot' => $category->name,
                    'category_classification_snapshot' => $category->classification,
                    'category_origin_snapshot' => $category->origin,
                    'category_color_snapshot' => $category->color,
                    'category_icon_snapshot' => $category->icon,
                ]);

                return $plan;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw BudgetStateException::planConflict();
            }

            throw $exception;
        }
    }

    public function updatePlan(User $user, MonthlyBudget $budget, UpdateBudgetPlanData $data): BudgetCategoryPlan
    {
        $plan = $this->requirePlan($budget, $data->planId);
        $this->assertPlanMutable($plan);
        $plan->planned_amount_centavos = $data->plannedAmountCentavos;
        $plan->save();

        return $plan;
    }

    public function removePlan(User $user, MonthlyBudget $budget, int $planId): void
    {
        $plan = $this->requirePlan($budget, $planId);
        $this->assertPlanMutable($plan);
        $plan->delete();
    }

    /**
     * Copy only the planned amounts into an independent destination month. The
     * whole copy is all-or-nothing: an occupied destination or an archived
     * source category leaves every destination record untouched.
     */
    public function copyMonth(User $user, MonthlyBudget $source, CopyBudgetData $data): MonthlyBudget
    {
        if ((int) $source->budget_year === $data->destinationYear && (int) $source->budget_month === $data->destinationMonth) {
            throw BudgetStateException::copySameMonth();
        }

        try {
            return DB::transaction(function () use ($user, $source, $data): MonthlyBudget {
                $occupied = MonthlyBudget::query()
                    ->where('user_id', $user->id)
                    ->where('budget_year', $data->destinationYear)
                    ->where('budget_month', $data->destinationMonth)
                    ->lockForUpdate()
                    ->exists();

                if ($occupied) {
                    throw BudgetStateException::copyDestinationOccupied();
                }

                $sourcePlans = $source->plans()->with('category')->orderBy('id')->get();

                foreach ($sourcePlans as $plan) {
                    if ($plan->category?->status !== CategoryStatus::Active) {
                        throw BudgetStateException::copyArchivedCategory();
                    }
                }

                $destination = MonthlyBudget::query()->create([
                    'user_id' => $user->id,
                    'budget_year' => $data->destinationYear,
                    'budget_month' => $data->destinationMonth,
                ]);

                foreach ($sourcePlans as $plan) {
                    $category = $plan->category;

                    // The destination captures the current active category identity,
                    // never the source snapshot.
                    BudgetCategoryPlan::query()->create([
                        'monthly_budget_id' => $destination->id,
                        'category_id' => $category->id,
                        'planned_amount_centavos' => $plan->planned_amount_centavos,
                        'currency_code' => 'BRL',
                        'category_name_snapshot' => $category->name,
                        'category_classification_snapshot' => $category->classification,
                        'category_origin_snapshot' => $category->origin,
                        'category_color_snapshot' => $category->color,
                        'category_icon_snapshot' => $category->icon,
                    ]);
                }

                return $destination;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw BudgetStateException::copyDestinationOccupied();
            }

            throw $exception;
        }
    }

    public function requirePlan(MonthlyBudget $budget, int $planId): BudgetCategoryPlan
    {
        $plan = BudgetCategoryPlan::query()
            ->where('id', $planId)
            ->where('monthly_budget_id', $budget->id)
            ->first();

        if (! $plan instanceof BudgetCategoryPlan) {
            throw new NotFoundHttpException('Budget plan not found in this monthly budget.');
        }

        return $plan;
    }

    private function assertPlanMutable(BudgetCategoryPlan $plan): void
    {
        $plan->loadMissing('category');

        if ($plan->category?->status === CategoryStatus::Archived) {
            throw BudgetStateException::planReadOnly();
        }
    }

    private function eligibleExpenseCategory(User $user, int $categoryId): Category
    {
        // Foreign, unknown and unavailable identifiers stay indistinguishable.
        $category = $this->categories->findAvailable($user, $categoryId);

        if ($category->classification !== CategoryClassification::Expense || $category->status !== CategoryStatus::Active) {
            throw BudgetStateException::categoryUnavailable();
        }

        return $category;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique constraint failed') || str_contains($message, 'duplicate entry') || str_contains($message, 'unique constraint');
    }
}
