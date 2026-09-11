<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Enums\Categories\CategoryClassification;
use App\Enums\Transactions\TransactionStatus;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class FilterTransactionsTest extends TransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function filters_by_type_date_and_account_with_brazilian_dates(): void
    {
        $user = $this->signInUser();
        $income = $this->transaction($user, 'income', ['description' => 'Receita', 'transaction_date' => '2026-09-11']);
        $this->transaction($user, 'expense', ['description' => 'Despesa', 'transaction_date' => '2026-09-10']);
        $this->getJson("/api/v1/transactions?type=income&from=11/09/2026&to=11/09/2026&financial_account_id={$income->financial_account_id}")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $income->id);
    }

    #[Test]
    public function combined_filters_accept_iso_and_brazilian_dates_with_inclusive_bounds(): void
    {
        $user = $this->signInUser();
        [$account, $category] = $this->associations($user, CategoryClassification::Expense);
        [$otherAccount, $otherCategory] = $this->associations($user, CategoryClassification::Expense);

        $match = $this->transaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'transaction_date' => '2026-09-11',
            'status' => TransactionStatus::Effective,
            'description' => 'Conta combinada',
        ]);
        $this->transaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'transaction_date' => '2026-09-12',
            'status' => TransactionStatus::Effective,
        ]);
        $this->transaction($user, 'expense', [
            'financial_account_id' => $otherAccount->id,
            'category_id' => $otherCategory->id,
            'transaction_date' => '2026-09-11',
            'status' => TransactionStatus::Effective,
        ]);
        $this->transaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'transaction_date' => '2026-09-11',
            'status' => TransactionStatus::Pending,
        ]);

        $query = "type=expense&status=effective&financial_account_id={$account->id}&category_id={$category->id}";
        $this->getJson("/api/v1/transactions?{$query}&from=11/09/2026&to=11/09/2026")->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $match->id);
        $this->getJson("/api/v1/transactions?{$query}&from=2026-09-11&to=2026-09-11")->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $match->id);
        $this->getJson("/api/v1/transactions?{$query}&from=2026-09-12&to=2026-09-12")->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.description', fn ($value) => $value !== 'Conta combinada');
        $this->getJson('/api/v1/transactions?type=income')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/transactions?status=pending')->assertOk()->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function archived_account_and_category_filters_still_match_their_transactions(): void
    {
        $user = $this->signInUser();
        $account = FinancialAccount::factory()->archived()->create(['user_id' => $user->id]);
        $category = Category::factory()->archived()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Expense]);
        $match = $this->transaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'description' => 'Histórico arquivado',
        ]);
        $this->transaction($user, 'expense');

        $this->getJson("/api/v1/transactions?financial_account_id={$account->id}")->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('data.0.financial_account.status', 'archived');
        $this->getJson("/api/v1/transactions?category_id={$category->id}")->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('data.0.category.status', 'archived');
    }

    #[Test]
    public function foreign_or_unknown_filter_associations_return_privacy_safe_not_found(): void
    {
        $user = $this->signInUser();
        $foreign = User::factory()->create();
        $foreignAccount = FinancialAccount::factory()->create(['user_id' => $foreign->id]);
        $foreignCategory = Category::factory()->create(['user_id' => $foreign->id]);

        $this->getJson("/api/v1/transactions?financial_account_id={$foreignAccount->id}")->assertNotFound();
        $this->getJson("/api/v1/transactions?category_id={$foreignCategory->id}")->assertNotFound();
        $this->getJson('/api/v1/transactions?financial_account_id=999999')->assertNotFound();
        $this->getJson('/api/v1/transactions?category_id=999999')->assertNotFound();
    }

    #[Test]
    public function removed_view_lists_only_removed_transactions(): void
    {
        $user = $this->signInUser();
        $active = $this->transaction($user, 'expense', ['description' => 'Ativa']);
        $removed = $this->transaction($user, 'expense', ['description' => 'Removida', 'removed_at' => now()]);

        $this->getJson('/api/v1/transactions')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $active->id);
        $this->getJson('/api/v1/transactions?view=removed')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $removed->id)
            ->assertJsonPath('data.0.removed_at', fn ($value) => $value !== null);
    }

    #[Test]
    public function filter_validation_rejects_unknown_values_inverted_ranges_and_bad_dates(): void
    {
        $this->signInUser();

        $this->getJson('/api/v1/transactions?type=transfer')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->getJson('/api/v1/transactions?status=settled')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/v1/transactions?view=everything')->assertUnprocessable()->assertJsonValidationErrors('view');
        $this->getJson('/api/v1/transactions?from=2026-09-12&to=2026-09-11')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/transactions?from=31/02/2026')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/v1/transactions?to=2101-01-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/transactions?per_page=51')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/transactions?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }
}
