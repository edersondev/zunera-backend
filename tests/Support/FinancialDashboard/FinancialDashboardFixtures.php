<?php

declare(strict_types=1);

namespace Tests\Support\FinancialDashboard;

use App\Data\FinancialDashboard\DashboardPeriodData;
use App\Enums\Categories\CategoryClassification;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Transfers\TransferBalanceReconciler;
use Carbon\CarbonImmutable;

/**
 * Shared builders for the financial-dashboard suites. Every owner-scoped record
 * is attached to the same user so ownership assertions stay meaningful.
 */
trait FinancialDashboardFixtures
{
    protected const string DASHBOARD_BUSINESS_DATE = '2026-09-17';

    protected function dashboardBusinessDate(): string
    {
        return self::DASHBOARD_BUSINESS_DATE;
    }

    /** Freeze the Brazilian business clock so preset periods stay deterministic. */
    protected function freezeDashboardClock(?string $date = null): void
    {
        $businessDate = $date ?? $this->dashboardBusinessDate();

        $this->travelTo(CarbonImmutable::parse($businessDate.' 10:00:00', DashboardPeriodData::BUSINESS_TIMEZONE));
    }

    protected function dashboardSignIn(?string $businessDate = null): User
    {
        $this->freezeDashboardClock($businessDate);
        $user = User::factory()->create();

        $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    protected function dashboardAccount(User $user, array $attributes = []): FinancialAccount
    {
        return FinancialAccount::factory()->create(array_merge(['user_id' => $user->id], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    protected function dashboardArchivedAccount(User $user, array $attributes = []): FinancialAccount
    {
        return FinancialAccount::factory()->archived()->create(array_merge(['user_id' => $user->id], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    protected function dashboardCategory(User $user, string $classification = 'expense', array $attributes = []): Category
    {
        return Category::factory()->create(array_merge([
            'user_id' => $user->id,
            'classification' => $classification === 'income'
                ? CategoryClassification::Income
                : CategoryClassification::Expense,
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    protected function dashboardArchivedCategory(User $user, string $classification = 'expense', array $attributes = []): Category
    {
        return Category::factory()->archived()->create(array_merge([
            'user_id' => $user->id,
            'classification' => $classification === 'income'
                ? CategoryClassification::Income
                : CategoryClassification::Expense,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function dashboardTransaction(User $user, string $type = 'expense', array $attributes = []): Transaction
    {
        $account = FinancialAccount::query()->where('user_id', $user->id)->orderBy('id')->first()
            ?? $this->dashboardAccount($user);
        $category = Category::query()
            ->where('user_id', $user->id)
            ->where('classification', $type)
            ->orderBy('id')
            ->first()
            ?? $this->dashboardCategory($user, $type);

        return Transaction::factory()->create(array_merge([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => $type,
            'description' => $type === 'income' ? 'Salary' : 'Groceries',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function dashboardTransfer(User $user, array $attributes = [], bool $reconcile = false): Transfer
    {
        $accounts = FinancialAccount::query()->where('user_id', $user->id)->orderBy('id')->get();
        $source = $accounts->get(0) ?? $this->dashboardAccount($user);
        $destination = $accounts->get(1) ?? $this->dashboardAccount($user);

        $transfer = Transfer::factory()->create(array_merge([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
        ], $attributes));

        if ($reconcile && $transfer->countsTowardBalance()) {
            app(TransferBalanceReconciler::class)->reconcile(null, $transfer);
        }

        return $transfer;
    }
}
