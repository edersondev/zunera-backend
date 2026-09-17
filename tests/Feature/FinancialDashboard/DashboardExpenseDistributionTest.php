<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialDashboard;

use App\Enums\Transactions\TransactionStatus;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialDashboard\FinancialDashboardFixtures;
use Tests\TestCase;

final class DashboardExpenseDistributionTest extends TestCase
{
    use FinancialDashboardFixtures;
    use RefreshDatabase;

    #[Test]
    public function it_ranks_realized_expense_categories_with_exact_shares(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user);
        $housing = $this->dashboardCategory($user, 'expense', ['name' => 'Moradia']);
        $food = $this->dashboardCategory($user, 'expense', ['name' => 'Alimentação']);
        $salary = $this->dashboardCategory($user, 'income', ['name' => 'Salário']);

        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $housing->id,
            'amount_centavos' => 300_000,
            'transaction_date' => '2026-09-03',
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $food->id,
            'amount_centavos' => 100_000,
            'transaction_date' => '2026-09-04',
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $food->id,
            'amount_centavos' => 100_000,
            'transaction_date' => '2026-09-05',
        ]);
        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $account->id,
            'category_id' => $salary->id,
            'amount_centavos' => 900_000,
            'transaction_date' => '2026-09-05',
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $housing->id,
            'amount_centavos' => 500_000,
            'transaction_date' => '2026-08-20',
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $housing->id,
            'amount_centavos' => 700_000,
            'transaction_date' => '2026-09-06',
            'status' => TransactionStatus::Pending,
        ]);

        $response = $this->getJson('/api/v1/financial-dashboard/expense-distribution')->assertOk();

        $response->assertJsonPath('data.total_expenses.amount_centavos', 500_000)
            ->assertJsonPath('data.categories.0.category.name', 'Moradia')
            ->assertJsonPath('data.categories.0.total.amount_centavos', 300_000)
            ->assertJsonPath('data.categories.0.share_percent', 60)
            ->assertJsonPath('data.categories.0.rank', 1)
            ->assertJsonPath('data.categories.1.category.name', 'Alimentação')
            ->assertJsonPath('data.categories.1.total.amount_centavos', 200_000)
            ->assertJsonPath('data.categories.1.share_percent', 40)
            ->assertJsonPath('data.categories.1.rank', 2);

        self::assertCount(2, $response->json('data.categories'));
    }

    #[Test]
    public function archived_categories_keep_their_historical_contribution_and_status(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user);
        $archived = $this->dashboardArchivedCategory($user, 'expense', ['name' => 'Assinaturas']);

        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $archived->id,
            'amount_centavos' => 50_000,
            'transaction_date' => '2026-09-08',
        ]);

        $this->getJson('/api/v1/financial-dashboard/expense-distribution')
            ->assertOk()
            ->assertJsonPath('data.total_expenses.amount_centavos', 50_000)
            ->assertJsonPath('data.categories.0.category.name', 'Assinaturas')
            ->assertJsonPath('data.categories.0.category.status', 'archived')
            ->assertJsonPath('data.categories.0.share_percent', 100);
    }

    #[Test]
    public function a_period_without_realized_expenses_returns_an_empty_distribution(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user);
        $category = $this->dashboardCategory($user, 'expense');

        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'amount_centavos' => 30_000,
            'transaction_date' => '2026-07-04',
        ]);

        $this->getJson('/api/v1/financial-dashboard/expense-distribution')
            ->assertOk()
            ->assertJsonPath('data.total_expenses.amount_centavos', 0)
            ->assertJsonPath('data.categories', []);
    }

    #[Test]
    public function a_category_left_without_transactions_never_appears(): void
    {
        $user = $this->dashboardSignIn();
        $this->dashboardAccount($user);
        Category::factory()->create(['user_id' => $user->id, 'classification' => 'expense']);

        $this->getJson('/api/v1/financial-dashboard/expense-distribution')
            ->assertOk()
            ->assertJsonPath('data.categories', []);
    }
}
