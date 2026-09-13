<?php

declare(strict_types=1);

namespace Tests\Feature\Transfers;

use App\Models\FinancialAccount;
use App\Models\Transfer;
use App\Services\Transfers\TransferBalanceReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class RemoveAndRestoreTransfersTest extends TransferFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function removing_and_restoring_an_effective_transfer_reverses_and_reapplies_both_sides_once(): void
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

        $this->postJson("/api/v1/transfers/{$transfer->id}/remove", [], ['Idempotency-Key' => 'remove-1'])->assertOk();
        self::assertSame(500_000, $source->refresh()->current_balance_centavos);
        self::assertSame(200_000, $destination->refresh()->current_balance_centavos);
        $this->getJson('/api/v1/transfers?view=removed')->assertOk()->assertJsonPath('data.0.id', $transfer->id);

        $this->postJson("/api/v1/transfers/{$transfer->id}/remove", [], ['Idempotency-Key' => 'remove-2'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'transfer_already_removed');

        $this->postJson("/api/v1/transfers/{$transfer->id}/restore", [], ['Idempotency-Key' => 'restore-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'effective');
        self::assertSame(400_000, $source->refresh()->current_balance_centavos);
        self::assertSame(300_000, $destination->refresh()->current_balance_centavos);

        $this->postJson("/api/v1/transfers/{$transfer->id}/restore", [], ['Idempotency-Key' => 'restore-2'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'transfer_already_active');
    }

    #[Test]
    public function a_removed_future_transfer_restores_as_pending_and_rejects_explicit_effective(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user, 500_000, 200_000);
        $transfer = Transfer::factory()->removed()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 100_000,
            'status' => 'effective',
            'transfer_date' => today()->addWeek(),
        ]);

        $this->postJson("/api/v1/transfers/{$transfer->id}/restore", ['status' => 'effective'], ['Idempotency-Key' => 'restore-future-effective'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'effective_future_date');
        self::assertNotNull($transfer->refresh()->removed_at);
        self::assertSame(500_000, $source->refresh()->current_balance_centavos);

        $this->postJson("/api/v1/transfers/{$transfer->id}/restore", [], ['Idempotency-Key' => 'restore-future-default'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
        self::assertNull($transfer->refresh()->removed_at);
        self::assertSame(500_000, $source->refresh()->current_balance_centavos);
        self::assertSame(200_000, $destination->refresh()->current_balance_centavos);
    }

    #[Test]
    public function restoring_an_effective_transfer_rechecks_source_funds_and_stays_removed_on_failure(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user, 60_000, 0);
        $third = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 0]);

        $transfer = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 50_000,
            'transfer_date' => today(),
        ]);
        app(TransferBalanceReconciler::class)->reconcile(null, $transfer);
        $this->postJson("/api/v1/transfers/{$transfer->id}/remove", [], ['Idempotency-Key' => 'remove-before-drain'])->assertOk();

        $drain = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $third->id,
            'amount_centavos' => 55_000,
            'transfer_date' => today(),
        ]);
        app(TransferBalanceReconciler::class)->reconcile(null, $drain);
        self::assertSame(5_000, $source->refresh()->current_balance_centavos);

        $this->postJson("/api/v1/transfers/{$transfer->id}/restore", [], ['Idempotency-Key' => 'restore-without-funds'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'insufficient_source_balance');

        self::assertNotNull($transfer->refresh()->removed_at);
        self::assertSame(5_000, $source->refresh()->current_balance_centavos);
        self::assertSame(0, $destination->refresh()->current_balance_centavos);
        self::assertSame(55_000, $third->refresh()->current_balance_centavos);
    }

    #[Test]
    public function lifecycle_mutations_replay_exactly_and_surface_foreign_or_denied_targets_safely(): void
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

        $original = $this->postJson("/api/v1/transfers/{$transfer->id}/remove", [], ['Idempotency-Key' => 'replay-remove'])->assertOk();
        $this->postJson("/api/v1/transfers/{$transfer->id}/remove", [], ['Idempotency-Key' => 'replay-remove'])
            ->assertOk()
            ->assertExactJson($original->json());
        self::assertSame(500_000, $source->refresh()->current_balance_centavos);

        $foreign = Transfer::factory()->create();
        $this->postJson("/api/v1/transfers/{$foreign->id}/remove", [], ['Idempotency-Key' => 'foreign-remove'])->assertNotFound();
        $this->postJson("/api/v1/transfers/{$foreign->id}/restore", [], ['Idempotency-Key' => 'foreign-restore'])->assertNotFound();
        $this->postJson('/api/v1/transfers/999999/remove', [], ['Idempotency-Key' => 'missing-remove'])->assertNotFound();
    }

    #[Test]
    public function lifecycle_mutations_require_authentication_and_an_idempotency_key(): void
    {
        $transfer = Transfer::factory()->create();
        $this->postJson("/api/v1/transfers/{$transfer->id}/remove", [])->assertUnauthorized();

        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user);
        $owned = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
        ]);
        $this->postJson("/api/v1/transfers/{$owned->id}/remove", [])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }
}
