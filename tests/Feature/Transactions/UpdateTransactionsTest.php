<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Enums\Categories\CategoryClassification;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class UpdateTransactionsTest extends TransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_updates_fields_and_effective_future_date_returns_notice(): void
    {
        $user = $this->signInUser();
        $transaction = $this->transaction($user, 'expense', ['amount_centavos' => 100]);
        $this->patchJson("/api/v1/transactions/{$transaction->id}", ['description' => 'Conta corrigida', 'amount_centavos' => 250, 'transaction_date' => today()->addDay()->toDateString()], ['Idempotency-Key' => 'update-1'])
            ->assertOk()->assertJsonPath('data.description', 'Conta corrigida')->assertJsonPath('meta.notice.code', 'effective_future_date');
    }

    #[Test]
    public function owner_updates_every_editable_field_and_both_accounts_stay_reconciled(): void
    {
        $user = $this->signInUser();
        $first = FinancialAccount::factory()->create(['user_id' => $user->id, 'initial_balance_centavos' => 1_000, 'current_balance_centavos' => 1_000]);
        $second = FinancialAccount::factory()->create(['user_id' => $user->id, 'initial_balance_centavos' => 500, 'current_balance_centavos' => 500]);
        $expense = Category::factory()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Expense]);
        $income = Category::factory()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Income]);

        $transactionId = $this->postJson('/api/v1/transactions', [
            'financial_account_id' => $first->id,
            'category_id' => $expense->id,
            'type' => 'expense',
            'description' => 'Conta de energia',
            'amount_centavos' => 200,
            'transaction_date' => today()->toDateString(),
        ], ['Idempotency-Key' => 'update-all-create'])->assertCreated()->json('data.id');
        self::assertSame(800, $first->refresh()->current_balance_centavos);

        $this->patchJson("/api/v1/transactions/{$transactionId}", [
            'description' => 'Salário',
            'notes' => 'Pagamento mensal',
            'amount_centavos' => 250,
            'type' => 'income',
            'category_id' => $income->id,
            'financial_account_id' => $second->id,
            'transaction_date' => today()->subDays(2)->toDateString(),
            'status' => 'pending',
        ], ['Idempotency-Key' => 'update-all'])->assertOk()
            ->assertJsonPath('data.description', 'Salário')
            ->assertJsonPath('data.notes', 'Pagamento mensal')
            ->assertJsonPath('data.amount_centavos', 250)
            ->assertJsonPath('data.type', 'income')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.category.id', $income->id)
            ->assertJsonPath('data.financial_account.id', $second->id)
            ->assertJsonPath('data.transaction_date', today()->subDays(2)->toDateString());
        self::assertSame(1_000, $first->refresh()->current_balance_centavos);
        self::assertSame(500, $second->refresh()->current_balance_centavos);

        $this->patchJson("/api/v1/transactions/{$transactionId}", ['status' => 'effective'], ['Idempotency-Key' => 'update-effective'])
            ->assertOk()->assertJsonPath('data.status', 'effective');
        self::assertSame(750, $second->refresh()->current_balance_centavos);
    }

    #[Test]
    public function type_category_mismatch_and_foreign_or_unknown_associations_are_rejected(): void
    {
        $user = $this->signInUser();
        $transaction = $this->transaction($user, 'expense');
        $foreign = User::factory()->create();
        $foreignAccount = FinancialAccount::factory()->create(['user_id' => $foreign->id]);
        $foreignCategory = Category::factory()->create(['user_id' => $foreign->id, 'classification' => CategoryClassification::Expense]);

        $this->patchJson("/api/v1/transactions/{$transaction->id}", ['type' => 'income'], ['Idempotency-Key' => 'update-mismatch'])
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->patchJson("/api/v1/transactions/{$transaction->id}", ['financial_account_id' => $foreignAccount->id], ['Idempotency-Key' => 'update-foreign-account'])
            ->assertNotFound();
        $this->patchJson("/api/v1/transactions/{$transaction->id}", ['category_id' => $foreignCategory->id], ['Idempotency-Key' => 'update-foreign-category'])
            ->assertNotFound();
        $this->patchJson("/api/v1/transactions/{$transaction->id}", ['financial_account_id' => 999_999], ['Idempotency-Key' => 'update-missing-account'])
            ->assertNotFound();
        $this->patchJson("/api/v1/transactions/{$transaction->id}", ['category_id' => 999_999], ['Idempotency-Key' => 'update-missing-category'])
            ->assertNotFound();

        self::assertSame(0, $foreignAccount->refresh()->current_balance_centavos);
        self::assertSame($transaction->description, $transaction->refresh()->description);
    }

    #[Test]
    public function removed_transactions_cannot_be_edited_and_future_dates_keep_effective_status(): void
    {
        $user = $this->signInUser();
        $removed = $this->transaction($user, 'expense', ['amount_centavos' => 100]);
        $this->postJson("/api/v1/transactions/{$removed->id}/remove", [], ['Idempotency-Key' => 'update-removed-remove'])->assertOk();
        $this->patchJson("/api/v1/transactions/{$removed->id}", ['description' => 'Não permitido'], ['Idempotency-Key' => 'update-removed'])
            ->assertConflict()->assertJsonPath('code', 'transaction_edit_requires_restore');
        self::assertNotNull($removed->refresh()->removed_at);

        $effective = $this->transaction($user, 'expense', ['amount_centavos' => 100]);
        $this->patchJson("/api/v1/transactions/{$effective->id}", ['transaction_date' => today()->addDay()->toDateString()], ['Idempotency-Key' => 'update-future'])
            ->assertOk()
            ->assertJsonPath('data.status', 'effective')
            ->assertJsonPath('data.transaction_date', today()->addDay()->toDateString())
            ->assertJsonPath('meta.notice.code', 'effective_future_date');
    }

    #[Test]
    public function update_requires_idempotency_key_and_replays_matching_retries(): void
    {
        $user = $this->signInUser();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id, 'initial_balance_centavos' => 1_000, 'current_balance_centavos' => 1_000]);
        $category = Category::factory()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Expense]);

        $transactionId = $this->postJson('/api/v1/transactions', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'description' => 'Internet',
            'amount_centavos' => 200,
            'transaction_date' => today()->toDateString(),
        ], ['Idempotency-Key' => 'update-replay-create'])->assertCreated()->json('data.id');
        self::assertSame(800, $account->refresh()->current_balance_centavos);

        $this->patchJson("/api/v1/transactions/{$transactionId}", ['amount_centavos' => 250])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        self::assertSame(800, $account->refresh()->current_balance_centavos);

        $this->patchJson("/api/v1/transactions/{$transactionId}", ['amount_centavos' => 250], ['Idempotency-Key' => 'update-replay'])
            ->assertOk()->assertJsonPath('data.amount_centavos', 250);
        self::assertSame(750, $account->refresh()->current_balance_centavos);

        $this->patchJson("/api/v1/transactions/{$transactionId}", ['amount_centavos' => 250], ['Idempotency-Key' => 'update-replay'])
            ->assertOk()->assertJsonPath('data.amount_centavos', 250);
        self::assertSame(750, $account->refresh()->current_balance_centavos);

        $this->patchJson("/api/v1/transactions/{$transactionId}", ['amount_centavos' => 999], ['Idempotency-Key' => 'update-replay'])
            ->assertConflict()->assertJsonPath('code', 'idempotency_key_reused');
        self::assertSame(750, $account->refresh()->current_balance_centavos);
        $this->getJson("/api/v1/transactions/{$transactionId}")->assertOk()->assertJsonPath('data.amount_centavos', 250);
    }
}
