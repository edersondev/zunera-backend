<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Enums\Categories\CategoryClassification;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecordTransactionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_records_income_and_expense_once_per_idempotency_key(): void
    {
        $user = $this->signIn(User::factory()->create());
        $account = FinancialAccount::factory()->create(['user_id' => $user->id, 'initial_balance_centavos' => 10_000, 'current_balance_centavos' => 10_000]);
        $income = Category::factory()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Income]);
        $expense = Category::factory()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Expense]);

        $incomeResponse = $this->postJson('/api/v1/transactions', $this->payload($account, $income, 'income', 500), ['Idempotency-Key' => 'income-1']);
        $incomeResponse->assertCreated()->assertJsonPath('data.type', 'income')->assertJsonPath('data.notes', 'Salário setembro');
        $this->postJson('/api/v1/transactions', $this->payload($account, $income, 'income', 500), ['Idempotency-Key' => 'income-1'])
            ->assertCreated()->assertJsonPath('data.id', $incomeResponse->json('data.id'));
        $this->postJson('/api/v1/transactions', $this->payload($account, $expense, 'expense', 200), ['Idempotency-Key' => 'expense-1'])
            ->assertCreated()->assertJsonPath('data.type', 'expense');

        $this->assertSame(2, Transaction::count());
        $this->assertSame(10_300, $account->refresh()->current_balance_centavos);
    }

    #[Test]
    public function invalid_or_foreign_associations_have_safe_field_feedback(): void
    {
        $user = $this->signIn(User::factory()->create());
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $income = Category::factory()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Income]);

        $this->postJson('/api/v1/transactions', array_merge($this->payload($account, $income, 'income', 1), ['amount_centavos' => 0]), ['Idempotency-Key' => 'bad-amount'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount_centavos');
        $this->postJson('/api/v1/transactions', $this->payload($account, $income, 'expense', 100), ['Idempotency-Key' => 'bad-category'])
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $foreign = FinancialAccount::factory()->create();
        $this->postJson('/api/v1/transactions', $this->payload($foreign, $income, 'income', 100), ['Idempotency-Key' => 'foreign-account'])->assertNotFound();
    }

    #[Test]
    public function guest_cannot_record_a_transaction(): void
    {
        $this->postJson('/api/v1/transactions', [], ['Idempotency-Key' => 'guest'])->assertUnauthorized();
    }

    /** @return array<string, mixed> */
    private function payload(FinancialAccount $account, Category $category, string $type, int $amount): array
    {
        return ['financial_account_id' => $account->id, 'category_id' => $category->id, 'type' => $type, 'description' => 'Lançamento teste', 'notes' => 'Salário setembro', 'amount_centavos' => $amount, 'transaction_date' => today()->toDateString()];
    }

    private function signIn(User $user): User
    {
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        return $user;
    }
}
