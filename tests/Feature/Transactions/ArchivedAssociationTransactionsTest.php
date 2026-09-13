<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Enums\Categories\CategoryClassification;
use App\Models\Category;
use App\Models\FinancialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class ArchivedAssociationTransactionsTest extends TransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function archived_associations_stay_visible_editable_and_balanced(): void
    {
        $user = $this->signInUser();
        $account = FinancialAccount::factory()->create([
            'user_id' => $user->id,
            'initial_balance_centavos' => 1_000,
            'current_balance_centavos' => 1_000,
        ]);
        $category = Category::factory()->create([
            'user_id' => $user->id,
            'classification' => CategoryClassification::Expense,
        ]);

        $created = $this->postJson('/api/v1/transactions', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'description' => 'Conta de luz',
            'amount_centavos' => 200,
            'transaction_date' => today()->toDateString(),
        ], ['Idempotency-Key' => 'archived-create'])->assertCreated();
        $transactionId = $created->json('data.id');
        self::assertSame(800, $account->refresh()->current_balance_centavos);

        $this->postJson("/api/v1/financial-accounts/{$account->id}/archive")->assertOk();
        $this->postJson("/api/v1/categories/{$category->id}/archive")->assertOk();
        self::assertSame(800, $account->refresh()->current_balance_centavos);

        $this->getJson('/api/v1/transactions')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $transactionId)
            ->assertJsonPath('data.0.financial_account.status', 'archived')
            ->assertJsonPath('data.0.category.status', 'archived');

        $this->patchJson("/api/v1/transactions/{$transactionId}", [
            'description' => 'Conta de luz corrigida',
            'amount_centavos' => 250,
        ], ['Idempotency-Key' => 'archived-edit'])->assertOk()
            ->assertJsonPath('data.description', 'Conta de luz corrigida')
            ->assertJsonPath('data.amount_centavos', 250)
            ->assertJsonPath('data.financial_account.status', 'archived')
            ->assertJsonPath('data.category.status', 'archived');
        self::assertSame(750, $account->refresh()->current_balance_centavos);

        $otherArchivedAccount = FinancialAccount::factory()->archived()->create(['user_id' => $user->id]);
        $this->patchJson("/api/v1/transactions/{$transactionId}", [
            'financial_account_id' => $otherArchivedAccount->id,
        ], ['Idempotency-Key' => 'archived-switch'])->assertUnprocessable()
            ->assertJsonValidationErrors('financial_account_id');
        self::assertSame(750, $account->refresh()->current_balance_centavos);
        self::assertSame(0, $otherArchivedAccount->refresh()->current_balance_centavos);
    }

    #[Test]
    public function archiving_resources_with_pending_transactions_keeps_state_and_balance(): void
    {
        $user = $this->signInUser();
        $account = FinancialAccount::factory()->create([
            'user_id' => $user->id,
            'initial_balance_centavos' => 1_000,
            'current_balance_centavos' => 1_000,
        ]);
        $category = Category::factory()->create([
            'user_id' => $user->id,
            'classification' => CategoryClassification::Expense,
        ]);

        $created = $this->postJson('/api/v1/transactions', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'description' => 'Assinatura futura',
            'amount_centavos' => 300,
            'transaction_date' => today()->addDays(5)->toDateString(),
        ], ['Idempotency-Key' => 'archived-pending-create'])->assertCreated()
            ->assertJsonPath('data.status', 'pending');
        $transactionId = $created->json('data.id');
        self::assertSame(1_000, $account->refresh()->current_balance_centavos);

        $this->postJson("/api/v1/financial-accounts/{$account->id}/archive")->assertOk();
        $this->postJson("/api/v1/categories/{$category->id}/archive")->assertOk();

        self::assertSame(1_000, $account->refresh()->current_balance_centavos);
        $this->getJson('/api/v1/transactions')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $transactionId)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.removed_at', null)
            ->assertJsonPath('data.0.financial_account.status', 'archived')
            ->assertJsonPath('data.0.category.status', 'archived');
    }
}
