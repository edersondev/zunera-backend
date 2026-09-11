<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Enums\Categories\CategoryClassification;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BalanceConsistencyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function effective_create_updates_account_summary_once(): void
    {
        $user = $this->signIn(User::factory()->create());
        $account = FinancialAccount::factory()->create(['user_id' => $user->id, 'initial_balance_centavos' => 100, 'current_balance_centavos' => 100]);
        $category = Category::factory()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Income]);
        $this->postJson('/api/v1/transactions', ['financial_account_id' => $account->id, 'category_id' => $category->id, 'type' => 'income', 'description' => 'Receita', 'amount_centavos' => 50, 'transaction_date' => today()->toDateString()], ['Idempotency-Key' => 'balance-create'])->assertCreated();
        self::assertSame(150, $account->refresh()->current_balance_centavos);
    }

    #[Test]
    public function update_move_remove_and_restore_reconcile_each_account_once(): void
    {
        $user = $this->signIn(User::factory()->create());
        $first = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 1_000]);
        $second = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 500]);
        $income = Category::factory()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Income]);
        $expense = Category::factory()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Expense]);
        $created = $this->postJson('/api/v1/transactions', ['financial_account_id' => $first->id, 'category_id' => $income->id, 'type' => 'income', 'description' => 'Receita', 'amount_centavos' => 200, 'transaction_date' => today()->toDateString()], ['Idempotency-Key' => 'balance-matrix-create'])->assertCreated();
        $id = $created->json('data.id');
        self::assertSame(1_200, $first->refresh()->current_balance_centavos);
        $this->getJson('/api/v1/financial-accounts/summary')->assertOk()->assertJsonPath('data.active_combined_balance_centavos', 1_700);
        $this->patchJson("/api/v1/transactions/{$id}", ['financial_account_id' => $second->id, 'category_id' => $expense->id, 'type' => 'expense'], ['Idempotency-Key' => 'balance-matrix-update'])->assertOk();
        self::assertSame(1_000, $first->refresh()->current_balance_centavos);
        self::assertSame(300, $second->refresh()->current_balance_centavos);
        $this->getJson("/api/v1/financial-accounts/{$second->id}")->assertOk()->assertJsonPath('data.current_balance_centavos', 300);
        $this->postJson("/api/v1/transactions/{$id}/remove", [], ['Idempotency-Key' => 'balance-matrix-remove'])->assertOk();
        self::assertSame(500, $second->refresh()->current_balance_centavos);
        $this->postJson("/api/v1/transactions/{$id}/restore", [], ['Idempotency-Key' => 'balance-matrix-restore'])->assertOk();
        self::assertSame(300, $second->refresh()->current_balance_centavos);
    }

    private function signIn(User $user): User
    {
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        return $user;
    }
}
