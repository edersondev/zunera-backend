<?php

declare(strict_types=1);

namespace Tests\Feature\Transfers;

use App\Models\FinancialAccount;
use App\Models\Transfer;
use App\Services\Transfers\TransferBalanceReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class TransferBalanceConsistencyTest extends TransferFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function multi_account_moves_preserve_every_individual_balance_exactly(): void
    {
        $user = $this->signInUser();
        $a = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 300_000]);
        $b = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 100_000]);
        $c = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 0]);

        $first = $this->postJson('/api/v1/transfers', [
            'source_financial_account_id' => $a->id,
            'destination_financial_account_id' => $b->id,
            'amount_centavos' => 50_000,
            'transfer_date' => today()->toDateString(),
        ], ['Idempotency-Key' => 'move-a-b'])->assertCreated();
        $second = $this->postJson('/api/v1/transfers', [
            'source_financial_account_id' => $b->id,
            'destination_financial_account_id' => $c->id,
            'amount_centavos' => 20_000,
            'transfer_date' => today()->toDateString(),
        ], ['Idempotency-Key' => 'move-b-c'])->assertCreated();

        self::assertSame(250_000, $a->refresh()->current_balance_centavos);
        self::assertSame(130_000, $b->refresh()->current_balance_centavos);
        self::assertSame(20_000, $c->refresh()->current_balance_centavos);
        self::assertSame(400_000, $a->current_balance_centavos + $b->current_balance_centavos + $c->current_balance_centavos);

        $this->getJson('/api/v1/financial-accounts/summary')
            ->assertOk()
            ->assertJsonPath('data.active_combined_balance_centavos', 400_000);

        $this->patchJson("/api/v1/transfers/{$first->json('data.id')}", [
            'destination_financial_account_id' => $c->id,
            'amount_centavos' => 30_000,
        ], ['Idempotency-Key' => 'move-a-c'])->assertOk();

        self::assertSame(270_000, $a->refresh()->current_balance_centavos);
        self::assertSame(80_000, $b->refresh()->current_balance_centavos);
        self::assertSame(50_000, $c->refresh()->current_balance_centavos);
        self::assertSame(400_000, $a->current_balance_centavos + $b->current_balance_centavos + $c->current_balance_centavos);
        self::assertSame(2, Transfer::count());
        self::assertSame(2, Transfer::query()->counting()->count());

        $this->postJson("/api/v1/transfers/{$second->json('data.id')}/remove", [], ['Idempotency-Key' => 'remove-move-b-c'])->assertOk();
        self::assertSame(400_000, $a->refresh()->current_balance_centavos + $b->refresh()->current_balance_centavos + $c->refresh()->current_balance_centavos);
        self::assertSame(100_000, $b->current_balance_centavos);
        self::assertSame(30_000, $c->current_balance_centavos);
        self::assertSame(1, Transfer::query()->counting()->count());
    }

    #[Test]
    public function pending_and_effective_status_changes_never_create_or_destroy_money(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user, 500_000, 200_000);
        $transfer = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 100_000,
            'status' => 'pending',
            'transfer_date' => today(),
        ]);
        $combined = 700_000;

        foreach (['effective', 'pending', 'effective'] as $index => $status) {
            $this->patchJson("/api/v1/transfers/{$transfer->id}", ['status' => $status], ['Idempotency-Key' => "status-{$index}"])->assertOk();
            self::assertSame($combined, $source->refresh()->current_balance_centavos + $destination->refresh()->current_balance_centavos);
        }

        self::assertSame(400_000, $source->refresh()->current_balance_centavos);
        self::assertSame(300_000, $destination->refresh()->current_balance_centavos);

        $this->postJson("/api/v1/transfers/{$transfer->id}/remove", [], ['Idempotency-Key' => 'consistency-remove'])->assertOk();
        self::assertSame($combined, $source->refresh()->current_balance_centavos + $destination->refresh()->current_balance_centavos);

        $this->postJson("/api/v1/transfers/{$transfer->id}/restore", [], ['Idempotency-Key' => 'consistency-restore'])->assertOk();
        self::assertSame($combined, $source->refresh()->current_balance_centavos + $destination->refresh()->current_balance_centavos);
        self::assertSame(400_000, $source->current_balance_centavos);
        self::assertSame(300_000, $destination->current_balance_centavos);
    }

    #[Test]
    public function archived_associations_keep_working_while_the_account_leaves_active_choices(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user, 500_000, 200_000);
        $transfer = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 100_000,
            'transfer_date' => today(),
        ]);
        app(TransferBalanceReconciler::class)->reconcile(null, $transfer);

        $this->postJson("/api/v1/financial-accounts/{$source->id}/archive")->assertOk();

        self::assertSame(400_000, $source->refresh()->current_balance_centavos);
        $this->getJson("/api/v1/transfers/{$transfer->id}")
            ->assertOk()
            ->assertJsonPath('data.source_financial_account.status', 'archived');
        $this->patchJson("/api/v1/transfers/{$transfer->id}", ['description' => 'Histórico mantido'], ['Idempotency-Key' => 'archived-edit'])
            ->assertOk()
            ->assertJsonPath('data.source_financial_account.status', 'archived');
    }
}
