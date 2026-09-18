<?php

declare(strict_types=1);

namespace Tests\Support\Budgets;

use App\Data\Budgets\BudgetMonthData;
use App\Enums\Categories\CategoryClassification;
use App\Enums\Transactions\TransactionStatus;
use App\Enums\Transactions\TransactionType;
use App\Models\BudgetCategoryPlan;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\MonthlyBudget;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Shared builders for the budgets suites. Every owner-scoped record is attached
 * to the same user so ownership assertions stay meaningful, and every money
 * value stays in exact centavos.
 */
trait BudgetFixtures
{
    protected const string BUDGET_BUSINESS_DATE = '2026-09-17';

    protected function budgetBusinessDate(): string
    {
        return self::BUDGET_BUSINESS_DATE;
    }

    /** Freeze the Brazilian business clock so current/future projections stay deterministic. */
    protected function freezeBudgetClock(?string $date = null): void
    {
        $businessDate = $date ?? $this->budgetBusinessDate();

        $this->travelTo(CarbonImmutable::parse($businessDate.' 10:00:00', BudgetMonthData::BUSINESS_TIMEZONE));
    }

    protected function budgetSignIn(?string $businessDate = null): User
    {
        $this->freezeBudgetClock($businessDate);
        $user = User::factory()->create();

        $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    protected function budgetAccount(User $user, array $attributes = []): FinancialAccount
    {
        return FinancialAccount::factory()->create(array_merge(['user_id' => $user->id], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    protected function budgetCategory(User $user, string $classification = 'expense', array $attributes = []): Category
    {
        return Category::factory()->create(array_merge([
            'user_id' => $user->id,
            'classification' => $this->budgetClassification($classification),
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    protected function budgetArchivedCategory(User $user, string $classification = 'expense', array $attributes = []): Category
    {
        return Category::factory()->archived()->create(array_merge([
            'user_id' => $user->id,
            'classification' => $this->budgetClassification($classification),
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    protected function budgetTransaction(User $user, string $type = 'expense', array $attributes = []): Transaction
    {
        $account = FinancialAccount::query()->where('user_id', $user->id)->orderBy('id')->first()
            ?? $this->budgetAccount($user);
        $category = Category::query()
            ->where('user_id', $user->id)
            ->where('classification', $this->budgetClassification($type))
            ->orderBy('id')
            ->first()
            ?? $this->budgetCategory($user, $type);

        return Transaction::factory()->create(array_merge([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => $type === 'income' ? TransactionType::Income : TransactionType::Expense,
            'status' => TransactionStatus::Effective,
            'description' => $type === 'income' ? 'Salary' : 'Groceries',
        ], $attributes));
    }

    /** Exact-centavo expense in a specific category and month. */
    protected function budgetExpense(User $user, Category $category, int $amountCentavos, string $date, array $attributes = []): Transaction
    {
        return $this->budgetTransaction($user, 'expense', array_merge([
            'category_id' => $category->id,
            'amount_centavos' => $amountCentavos,
            'transaction_date' => $date,
        ], $attributes));
    }

    protected function budgetMonth(User $user, int $year, int $month): MonthlyBudget
    {
        return MonthlyBudget::factory()->create([
            'user_id' => $user->id,
            'budget_year' => $year,
            'budget_month' => $month,
        ]);
    }

    protected function budgetPlan(MonthlyBudget $budget, Category $category, int $plannedAmountCentavos): BudgetCategoryPlan
    {
        return BudgetCategoryPlan::factory()->create([
            'monthly_budget_id' => $budget->id,
            'category_id' => $category->id,
            'planned_amount_centavos' => $plannedAmountCentavos,
            'category_name_snapshot' => $category->name,
            'category_classification_snapshot' => CategoryClassification::Expense,
            'category_origin_snapshot' => $category->origin,
            'category_color_snapshot' => $category->color,
            'category_icon_snapshot' => $category->icon,
        ]);
    }

    protected function budgetPath(int $year, int $month): string
    {
        return sprintf('/api/v1/budgets/%d/%d', $year, $month);
    }

    /** @param array<string, mixed> $attributes */
    protected function budgetTransfer(User $user, array $attributes = []): Transfer
    {
        $accounts = FinancialAccount::query()->where('user_id', $user->id)->orderBy('id')->get();
        $source = $accounts->get(0) ?? $this->budgetAccount($user);
        $destination = $accounts->get(1) ?? $this->budgetAccount($user);

        return Transfer::factory()->create(array_merge([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
        ], $attributes));
    }

    private function budgetClassification(string $type): CategoryClassification
    {
        return $type === 'income' ? CategoryClassification::Income : CategoryClassification::Expense;
    }
}
