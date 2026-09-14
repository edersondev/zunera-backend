<?php

declare(strict_types=1);

namespace Tests\Unit\Transfers;

use App\Enums\Transfers\TransferStatus;
use App\Exceptions\Transfers\TransferStateException;
use App\Models\FinancialAccount;
use App\Models\Transfer;
use App\Models\User;
use App\Services\FinancialAccounts\FinancialAccountMoney;
use App\Services\Transfers\TransferBalanceReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TransferBalanceReconcilerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function effective_transfer_debits_source_and_credits_destination_exactly_once(): void
    {
        [$user, $source, $destination] = $this->sides(500_000, 200_000);
        $transfer = $this->transfer($user, $source, $destination, 100_000, TransferStatus::Effective);

        $proposed = app(TransferBalanceReconciler::class)->reconcile(null, $transfer);

        self::assertSame(400_000, $source->refresh()->current_balance_centavos);
        self::assertSame(300_000, $destination->refresh()->current_balance_centavos);
        self::assertSame(700_000, $source->current_balance_centavos + $destination->current_balance_centavos);
        self::assertSame(400_000, $proposed[$source->id]);
        self::assertSame(300_000, $proposed[$destination->id]);
    }

    #[Test]
    public function pending_and_removed_states_contribute_nothing(): void
    {
        [$user, $source, $destination] = $this->sides(500_000, 200_000);
        $pending = $this->transfer($user, $source, $destination, 100_000, TransferStatus::Pending);
        self::assertSame([], app(TransferBalanceReconciler::class)->reconcile(null, $pending));
        self::assertSame(500_000, $source->refresh()->current_balance_centavos);
        self::assertSame(200_000, $destination->refresh()->current_balance_centavos);

        $effective = $this->transfer($user, $source, $destination, 100_000, TransferStatus::Effective);
        app(TransferBalanceReconciler::class)->reconcile(null, $effective);
        $before = clone $effective;
        $effective->removed_at = now();
        $effective->save();
        app(TransferBalanceReconciler::class)->reconcile($before, $effective);

        self::assertSame(500_000, $source->refresh()->current_balance_centavos);
        self::assertSame(200_000, $destination->refresh()->current_balance_centavos);
    }

    #[Test]
    public function status_and_side_and_amount_changes_move_effects_between_accounts(): void
    {
        [$user, $source, $destination] = $this->sides(500_000, 200_000);
        $third = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 0]);
        $transfer = $this->transfer($user, $source, $destination, 100_000, TransferStatus::Effective);
        $reconciler = app(TransferBalanceReconciler::class);
        $reconciler->reconcile(null, $transfer);

        $before = clone $transfer;
        $transfer->destination_financial_account_id = $third->id;
        $transfer->amount_centavos = 40_000;
        $transfer->save();
        $reconciler->reconcile($before, $transfer);

        self::assertSame(460_000, $source->refresh()->current_balance_centavos);
        self::assertSame(200_000, $destination->refresh()->current_balance_centavos);
        self::assertSame(40_000, $third->refresh()->current_balance_centavos);

        $before = clone $transfer;
        $transfer->status = TransferStatus::Pending;
        $transfer->save();
        $reconciler->reconcile($before, $transfer);

        self::assertSame(500_000, $source->refresh()->current_balance_centavos);
        self::assertSame(0, $third->refresh()->current_balance_centavos);
    }

    #[Test]
    public function source_overdraft_is_rejected_without_touching_any_balance(): void
    {
        [$user, $source, $destination] = $this->sides(50_000, 200_000);
        $transfer = $this->transfer($user, $source, $destination, 60_000, TransferStatus::Effective);

        try {
            app(TransferBalanceReconciler::class)->reconcile(null, $transfer);
            self::fail('Expected the overdraft to be rejected.');
        } catch (TransferStateException $exception) {
            self::assertSame('insufficient_source_balance', $exception->errorCode());
        }

        self::assertSame(50_000, $source->refresh()->current_balance_centavos);
        self::assertSame(200_000, $destination->refresh()->current_balance_centavos);
    }

    #[Test]
    public function destination_outside_supported_range_is_rejected(): void
    {
        [$user, $source, $destination] = $this->sides(500_000, FinancialAccountMoney::MAX_CENTAVOS);
        $transfer = $this->transfer($user, $source, $destination, 1, TransferStatus::Effective);

        try {
            app(TransferBalanceReconciler::class)->reconcile(null, $transfer);
            self::fail('Expected the destination range breach to be rejected.');
        } catch (TransferStateException $exception) {
            self::assertSame('account_balance_out_of_range', $exception->errorCode());
        }

        self::assertSame(500_000, $source->refresh()->current_balance_centavos);
        self::assertSame(FinancialAccountMoney::MAX_CENTAVOS, $destination->refresh()->current_balance_centavos);
    }

    #[Test]
    public function opposing_side_orderings_reconcile_deterministically(): void
    {
        [$user, $source, $destination] = $this->sides(500_000, 200_000);
        $reconciler = app(TransferBalanceReconciler::class);
        $high = max($source->id, $destination->id);
        $low = min($source->id, $destination->id);

        foreach ([[$source, $destination], [$destination, $source]] as [$from, $to]) {
            $transfer = $this->transfer($user, $from, $to, 10_000, TransferStatus::Effective);
            $proposed = $reconciler->reconcile(null, $transfer);
            self::assertSame([$low, $high], array_keys($proposed));
        }
    }

    /** @return array{0: User, 1: FinancialAccount, 2: FinancialAccount} */
    private function sides(int $sourceBalance, int $destinationBalance): array
    {
        $user = User::factory()->create();

        return [
            $user,
            FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => $sourceBalance]),
            FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => $destinationBalance]),
        ];
    }

    private function transfer(User $user, FinancialAccount $source, FinancialAccount $destination, int $amount, TransferStatus $status): Transfer
    {
        return Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => $amount,
            'status' => $status,
            'transfer_date' => today(),
        ]);
    }
}
