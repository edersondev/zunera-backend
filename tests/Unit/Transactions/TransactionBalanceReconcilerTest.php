<?php

declare(strict_types=1);

namespace Tests\Unit\Transactions;

use App\Enums\Transactions\TransactionStatus;
use App\Enums\Transactions\TransactionType;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\TransactionBalanceReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TransactionBalanceReconcilerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function applies_delta_for_create_status_type_account_and_removal_changes(): void
    {
        $user = User::factory()->create();
        $one = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 1_000]);
        $two = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 2_000]);
        $transaction = Transaction::factory()->create(['user_id' => $user->id, 'financial_account_id' => $one->id, 'type' => TransactionType::Income, 'status' => TransactionStatus::Effective, 'amount_centavos' => 250]);
        $reconciler = app(TransactionBalanceReconciler::class);
        $reconciler->reconcile(null, $transaction);
        self::assertSame(1_250, $one->refresh()->current_balance_centavos);

        $before = clone $transaction;
        $transaction->type = TransactionType::Expense;
        $transaction->financial_account_id = $two->id;
        $transaction->save();
        $reconciler->reconcile($before, $transaction);
        self::assertSame(1_000, $one->refresh()->current_balance_centavos);
        self::assertSame(1_750, $two->refresh()->current_balance_centavos);

        $before = clone $transaction;
        $transaction->removed_at = now();
        $transaction->save();
        $reconciler->reconcile($before, $transaction);
        self::assertSame(2_000, $two->refresh()->current_balance_centavos);
    }
}
