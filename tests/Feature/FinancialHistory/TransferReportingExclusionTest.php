<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialHistory;

use App\Data\FinancialHistory\FinancialHistoryFilterData;
use App\Enums\Categories\CategoryClassification;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\FinancialAccounts\FinancialAccountService;
use App\Services\FinancialHistory\FinancialHistoryService;
use App\Services\Transfers\TransferBalanceReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TransferReportingExclusionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function financial_history_service_excludes_effective_transfers_from_every_reporting_total(): void
    {
        $user = User::factory()->create();
        $source = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 500_000]);
        $destination = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 0]);
        $this->movement($user, $source, CategoryClassification::Income, 200_000);
        $this->movement($user, $source, CategoryClassification::Expense, 50_000);

        $service = app(FinancialHistoryService::class);
        $baseline = $service->totals($user);
        self::assertSame(200_000, $baseline['income_centavos']);
        self::assertSame(50_000, $baseline['expense_centavos']);
        self::assertSame(150_000, $baseline['financial_result_centavos']);

        $effective = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 120_000,
            'transfer_date' => today(),
        ]);
        app(TransferBalanceReconciler::class)->reconcile(null, $effective);

        $pending = Transfer::factory()->pending()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 90_000,
        ]);
        $removed = Transfer::factory()->removed()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 70_000,
        ]);

        self::assertSame($baseline, $service->totals($user));
        self::assertSame('transfer', $this->entry($service, $user, 'transfer'));
        self::assertNull($pending->refresh()->removed_at);
        self::assertNotNull($removed->refresh()->removed_at);
    }

    #[Test]
    public function financial_account_service_applies_both_side_effects_and_preserves_owned_net_worth(): void
    {
        $user = User::factory()->create();
        $source = FinancialAccount::factory()->create([
            'user_id' => $user->id,
            'initial_balance_centavos' => 500_000,
            'current_balance_centavos' => 500_000,
        ]);
        $destination = FinancialAccount::factory()->create([
            'user_id' => $user->id,
            'initial_balance_centavos' => 100_000,
            'current_balance_centavos' => 100_000,
        ]);
        $accounts = app(FinancialAccountService::class);
        $before = $accounts->summary($user);
        self::assertSame(600_000, $before['active_combined_balance_centavos']);

        $transfer = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 150_000,
            'transfer_date' => today(),
        ]);
        app(TransferBalanceReconciler::class)->reconcile(null, $transfer);

        $after = $accounts->summary($user);
        self::assertSame(350_000, $source->refresh()->current_balance_centavos);
        self::assertSame(250_000, $destination->refresh()->current_balance_centavos);
        self::assertSame(600_000, $after['active_combined_balance_centavos']);
        self::assertSame($before['active_account_count'], $after['active_account_count']);
    }

    private function entry(FinancialHistoryService $service, User $user, string $movementKind): ?string
    {
        $paginator = $service->list($user, new FinancialHistoryFilterData(movementKind: $movementKind));
        $items = $paginator->items();

        return $items === [] ? null : $items[0]['movement_kind'];
    }

    private function movement(User $user, FinancialAccount $account, CategoryClassification $classification, int $amount): Transaction
    {
        $category = Category::factory()->create(['user_id' => $user->id, 'classification' => $classification]);

        return Transaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => $classification === CategoryClassification::Income ? 'income' : 'expense',
            'amount_centavos' => $amount,
            'transaction_date' => today(),
        ]);
    }
}
