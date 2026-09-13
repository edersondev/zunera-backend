<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Enums\Categories\CategoryClassification;
use App\Enums\Transactions\TransactionStatus;
use App\Models\Category;
use App\Models\FinancialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class RemoveAndRestoreTransactionsTest extends TransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_removes_then_restores_transaction_with_state_conflicts(): void
    {
        $user = $this->signInUser();
        $transaction = $this->transaction($user);
        $this->postJson("/api/v1/transactions/{$transaction->id}/remove", [], ['Idempotency-Key' => 'remove-1'])->assertOk()->assertJsonPath('data.removed_at', fn ($value) => $value !== null);
        $this->getJson('/api/v1/transactions')->assertJsonPath('meta.total', 0);
        $this->postJson("/api/v1/transactions/{$transaction->id}/remove", [], ['Idempotency-Key' => 'remove-2'])->assertConflict()->assertJsonPath('code', 'transaction_already_removed');
        $this->postJson("/api/v1/transactions/{$transaction->id}/restore", [], ['Idempotency-Key' => 'restore-1'])->assertOk()->assertJsonPath('data.removed_at', null);
        $this->postJson("/api/v1/transactions/{$transaction->id}/restore", [], ['Idempotency-Key' => 'restore-2'])->assertConflict()->assertJsonPath('code', 'transaction_already_active');
    }

    #[Test]
    public function removal_reverses_balance_hides_from_history_and_restore_reapplies_it(): void
    {
        $user = $this->signInUser();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id, 'initial_balance_centavos' => 1_000, 'current_balance_centavos' => 1_000]);
        $category = Category::factory()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Expense]);

        $transactionId = $this->postJson('/api/v1/transactions', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'description' => 'Água',
            'amount_centavos' => 200,
            'transaction_date' => today()->toDateString(),
        ], ['Idempotency-Key' => 'lifecycle-create'])->assertCreated()->json('data.id');
        self::assertSame(800, $account->refresh()->current_balance_centavos);

        $this->postJson("/api/v1/transactions/{$transactionId}/remove", [], ['Idempotency-Key' => 'lifecycle-remove'])
            ->assertOk()->assertJsonPath('data.removed_at', fn ($value) => $value !== null)
            ->assertJsonPath('data.status', 'effective');
        self::assertSame(1_000, $account->refresh()->current_balance_centavos);

        $this->getJson('/api/v1/transactions')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/transactions?view=removed')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $transactionId);
        $this->getJson("/api/v1/transactions/{$transactionId}")->assertOk()
            ->assertJsonPath('data.removed_at', fn ($value) => $value !== null);

        $this->postJson("/api/v1/transactions/{$transactionId}/restore", [], ['Idempotency-Key' => 'lifecycle-restore'])
            ->assertOk()->assertJsonPath('data.removed_at', null)->assertJsonPath('data.status', 'effective');
        self::assertSame(800, $account->refresh()->current_balance_centavos);
        $this->getJson('/api/v1/transactions')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $transactionId);
    }

    #[Test]
    public function idempotent_retries_replay_and_reused_keys_conflict_without_balance_change(): void
    {
        $user = $this->signInUser();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id, 'initial_balance_centavos' => 1_000, 'current_balance_centavos' => 1_000]);
        $category = Category::factory()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Expense]);

        $transactionId = $this->postJson('/api/v1/transactions', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'description' => 'Telefone',
            'amount_centavos' => 200,
            'transaction_date' => today()->toDateString(),
        ], ['Idempotency-Key' => 'replay-create'])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/transactions/{$transactionId}/remove", [], ['Idempotency-Key' => 'replay-remove'])->assertOk();
        self::assertSame(1_000, $account->refresh()->current_balance_centavos);
        $this->postJson("/api/v1/transactions/{$transactionId}/remove", [], ['Idempotency-Key' => 'replay-remove'])->assertOk();
        self::assertSame(1_000, $account->refresh()->current_balance_centavos);

        $this->postJson("/api/v1/transactions/{$transactionId}/restore", [], ['Idempotency-Key' => 'replay-remove'])
            ->assertConflict()->assertJsonPath('code', 'idempotency_key_reused');
        self::assertSame(1_000, $account->refresh()->current_balance_centavos);
        self::assertNotNull($this->getJson("/api/v1/transactions/{$transactionId}")->json('data.removed_at'));

        $this->postJson("/api/v1/transactions/{$transactionId}/restore", [], ['Idempotency-Key' => 'replay-restore'])->assertOk();
        self::assertSame(800, $account->refresh()->current_balance_centavos);
        $this->postJson("/api/v1/transactions/{$transactionId}/restore", [], ['Idempotency-Key' => 'replay-restore'])->assertOk();
        self::assertSame(800, $account->refresh()->current_balance_centavos);
    }

    #[Test]
    public function restore_state_rules_follow_the_transaction_date(): void
    {
        $user = $this->signInUser();
        $past = $this->transaction($user, 'expense', ['amount_centavos' => 100]);
        $this->postJson("/api/v1/transactions/{$past->id}/remove", [], ['Idempotency-Key' => 'restore-past-remove'])->assertOk();
        $this->postJson("/api/v1/transactions/{$past->id}/restore", ['status' => 'pending'], ['Idempotency-Key' => 'restore-past'])
            ->assertOk()->assertJsonPath('data.status', 'pending');

        $future = $this->transaction($user, 'expense', [
            'amount_centavos' => 100,
            'transaction_date' => today()->addDays(3),
            'status' => TransactionStatus::Pending,
        ]);
        $this->postJson("/api/v1/transactions/{$future->id}/remove", [], ['Idempotency-Key' => 'restore-future-remove'])->assertOk();
        $this->postJson("/api/v1/transactions/{$future->id}/restore", ['status' => 'effective'], ['Idempotency-Key' => 'restore-future-effective'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/transactions/{$future->id}/restore", [], ['Idempotency-Key' => 'restore-future-default'])
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.removed_at', null);
    }
}
