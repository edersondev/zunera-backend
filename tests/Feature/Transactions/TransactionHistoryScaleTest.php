<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Enums\Categories\CategoryClassification;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class TransactionHistoryScaleTest extends TransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function five_thousand_transaction_history_is_paginated_and_reachable(): void
    {
        $user = $this->signInUser();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Expense]);
        Transaction::factory()->count(5_000)->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
        ]);
        $needle = Transaction::query()->firstOrFail();
        $needle->description = 'Escala pesquisável';
        $needle->save();

        $this->getJson('/api/v1/transactions?per_page=50')->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.total', 5_000)
            ->assertJsonPath('meta.last_page', 100);
        $this->getJson('/api/v1/transactions?per_page=50&page=100')->assertOk()
            ->assertJsonCount(50, 'data');
        $this->getJson("/api/v1/transactions?q=escala&financial_account_id={$account->id}&category_id={$category->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $needle->id);
    }
}
