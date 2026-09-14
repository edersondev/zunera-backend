<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialHistory;

use App\Enums\Categories\CategoryClassification;
use App\Enums\Transactions\TransactionStatus;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Transfers\TransferBalanceReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ListFinancialHistoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function mixed_history_labels_transfers_beside_income_and_expense_newest_first(): void
    {
        $user = $this->signIn();
        $source = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 500_000]);
        $destination = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 0]);
        $incomeTransaction = $this->transaction($user, $source, 'income', '2026-09-01');
        $expenseTransaction = $this->transaction($user, $source, 'expense', '2026-09-02');
        $transfer = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 10_000,
            'transfer_date' => '2026-09-03',
        ]);
        app(TransferBalanceReconciler::class)->reconcile(null, $transfer);

        $response = $this->getJson('/api/v1/financial-history')->assertOk()->assertJsonPath('meta.total', 3);

        self::assertSame(
            [['transfer', $transfer->id], ['expense', $expenseTransaction->id], ['income', $incomeTransaction->id]],
            array_map(fn (array $entry): array => [$entry['movement_kind'], $entry['id']], $response->json('data')),
        );
        $response->assertJsonPath('data.0.source_financial_account.id', $source->id)
            ->assertJsonPath('data.0.destination_financial_account.id', $destination->id)
            ->assertJsonPath('data.0.category', null)
            ->assertJsonPath('data.1.financial_account.id', $source->id)
            ->assertJsonPath('data.1.movement_kind', 'expense');
    }

    #[Test]
    public function movement_kind_and_account_filters_match_either_transfer_side(): void
    {
        $user = $this->signIn();
        $source = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $destination = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $this->transaction($user, $source, 'income', '2026-09-01');
        $this->transaction($user, $source, 'expense', '2026-09-01');
        Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
        ]);

        $this->getJson('/api/v1/financial-history?movement_kind=transfer')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.movement_kind', 'transfer');
        $this->getJson('/api/v1/financial-history?movement_kind=income')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.movement_kind', 'income');
        $this->getJson('/api/v1/financial-history?movement_kind=expense')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.movement_kind', 'expense');
        $this->getJson("/api/v1/financial-history?financial_account_id={$destination->id}")->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.movement_kind', 'transfer');
        $this->getJson('/api/v1/financial-history?status=pending')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/financial-history?per_page=51')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    #[Test]
    public function legacy_transaction_filters_and_removed_view_keep_their_existing_behavior(): void
    {
        $user = $this->signIn();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $incomeCategory = Category::factory()->create([
            'user_id' => $user->id,
            'classification' => CategoryClassification::Income,
        ]);
        $expenseCategory = Category::factory()->create([
            'user_id' => $user->id,
            'classification' => CategoryClassification::Expense,
        ]);
        $income = Transaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $incomeCategory->id,
            'type' => 'income',
        ]);
        $expense = Transaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $expenseCategory->id,
            'type' => 'expense',
        ]);
        $removed = Transaction::factory()->removed()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $expenseCategory->id,
            'type' => 'expense',
        ]);
        Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $account->id,
            'destination_financial_account_id' => FinancialAccount::factory()->create(['user_id' => $user->id])->id,
        ]);

        $this->getJson('/api/v1/financial-history?type=income')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $income->id);
        $this->getJson("/api/v1/financial-history?category_id={$expenseCategory->id}")->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $expense->id);
        $this->getJson('/api/v1/financial-history?view=removed')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $removed->id)
            ->assertJsonPath('data.0.movement_kind', 'expense');
    }

    #[Test]
    public function mixed_history_is_owner_scoped_and_privacy_safe(): void
    {
        $user = $this->signIn();
        $own = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $foreign = FinancialAccount::factory()->create();
        Transfer::factory()->create();
        $ownedTransfer = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $own->id,
            'destination_financial_account_id' => FinancialAccount::factory()->create(['user_id' => $user->id])->id,
        ]);

        $this->getJson('/api/v1/financial-history')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $ownedTransfer->id)
            ->assertJsonPath('data.0.movement_kind', 'transfer');
        $this->getJson("/api/v1/financial-history?financial_account_id={$foreign->id}")->assertNotFound();
        $this->getJson('/api/v1/financial-history?financial_account_id=999999')->assertNotFound();
    }

    #[Test]
    public function guest_access_is_denied_and_transaction_totals_ignore_transfers(): void
    {
        $this->getJson('/api/v1/financial-history')->assertUnauthorized();

        $user = $this->signIn();
        $source = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 500_000]);
        $destination = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 0]);
        $this->transaction($user, $source, 'income', '2026-09-01', 100_000);
        $this->transaction($user, $source, 'expense', '2026-09-02', 30_000);
        $before = $this->getJson('/api/v1/financial-history')->assertOk()->json('meta.totals');

        $transfer = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 40_000,
            'transfer_date' => '2026-09-03',
        ]);
        app(TransferBalanceReconciler::class)->reconcile(null, $transfer);

        $after = $this->getJson('/api/v1/financial-history')->assertOk()->json('meta.totals');
        self::assertSame($before, $after);
        self::assertSame(100_000, $after['income_centavos']);
        self::assertSame(30_000, $after['expense_centavos']);
        self::assertSame(70_000, $after['financial_result_centavos']);
    }

    #[Test]
    public function the_canonical_history_route_and_the_004_transaction_route_keep_single_owners(): void
    {
        $history = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => in_array('GET', $route->methods(), true) && $route->uri() === 'api/v1/financial-history');
        $transactions = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => in_array('GET', $route->methods(), true) && $route->uri() === 'api/v1/transactions');

        self::assertCount(1, $history);
        self::assertCount(1, $transactions);
        self::assertSame(TransactionController::class.'@index', $transactions->first()->getActionName());
    }

    private function signIn(): User
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        return $user;
    }

    private function transaction(User $user, FinancialAccount $account, string $type, string $date, int $amount = 10_000): Transaction
    {
        $category = Category::factory()->create([
            'user_id' => $user->id,
            'classification' => $type === 'income' ? CategoryClassification::Income : CategoryClassification::Expense,
        ]);

        return Transaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => $type,
            'status' => TransactionStatus::Effective,
            'amount_centavos' => $amount,
            'transaction_date' => $date,
            'description' => 'Movimento '.$type,
        ]);
    }
}
