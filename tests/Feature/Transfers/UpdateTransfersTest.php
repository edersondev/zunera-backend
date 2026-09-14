<?php

declare(strict_types=1);

namespace Tests\Feature\Transfers;

use App\Models\FinancialAccount;
use App\Models\Transfer;
use App\Services\Transfers\TransferBalanceReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class UpdateTransfersTest extends TransferFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function correcting_sides_amount_date_status_and_text_moves_both_balances_once(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user, 500_000, 200_000);
        $third = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 0]);

        $transfer = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 100_000,
            'transfer_date' => today(),
        ]);
        app(TransferBalanceReconciler::class)->reconcile(null, $transfer);
        self::assertSame(400_000, $source->refresh()->current_balance_centavos);
        self::assertSame(300_000, $destination->refresh()->current_balance_centavos);

        $this->patchJson("/api/v1/transfers/{$transfer->id}", [
            'destination_financial_account_id' => $third->id,
            'amount_centavos' => 40_000,
            'transfer_date' => today()->subDay()->toDateString(),
            'description' => 'Corrigida',
            'notes' => null,
        ], ['Idempotency-Key' => 'update-1'])
            ->assertOk()
            ->assertJsonPath('data.amount_centavos', 40_000)
            ->assertJsonPath('data.destination_financial_account.id', $third->id)
            ->assertJsonPath('data.description', 'Corrigida');

        self::assertSame(460_000, $source->refresh()->current_balance_centavos);
        self::assertSame(200_000, $destination->refresh()->current_balance_centavos);
        self::assertSame(40_000, $third->refresh()->current_balance_centavos);

        $this->patchJson("/api/v1/transfers/{$transfer->id}", ['status' => 'pending'], ['Idempotency-Key' => 'update-2'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        self::assertSame(500_000, $source->refresh()->current_balance_centavos);
        self::assertSame(40_000, $third->current_balance_centavos);
    }

    #[Test]
    public function archived_current_side_is_retained_while_replacement_sides_must_be_active_and_owned(): void
    {
        $user = $this->signInUser();
        $archived = FinancialAccount::factory()->archived()->create(['user_id' => $user->id]);
        $destination = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $replacement = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $archivedReplacement = FinancialAccount::factory()->archived()->create(['user_id' => $user->id]);
        $foreign = FinancialAccount::factory()->create();
        $transfer = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $archived->id,
            'destination_financial_account_id' => $destination->id,
            'status' => 'pending',
        ]);

        $this->patchJson("/api/v1/transfers/{$transfer->id}", ['description' => 'Retida'], ['Idempotency-Key' => 'archived-retain'])
            ->assertOk()
            ->assertJsonPath('data.source_financial_account.id', $archived->id);

        $this->patchJson("/api/v1/transfers/{$transfer->id}", ['source_financial_account_id' => $archivedReplacement->id], ['Idempotency-Key' => 'archived-replace'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_financial_account_id');
        $this->patchJson("/api/v1/transfers/{$transfer->id}", ['source_financial_account_id' => $foreign->id], ['Idempotency-Key' => 'foreign-replace'])
            ->assertNotFound();
        $this->patchJson("/api/v1/transfers/{$transfer->id}", ['source_financial_account_id' => $destination->id], ['Idempotency-Key' => 'same-side'])
            ->assertUnprocessable()->assertJsonValidationErrors('destination_financial_account_id');

        $this->patchJson("/api/v1/transfers/{$transfer->id}", ['source_financial_account_id' => $replacement->id], ['Idempotency-Key' => 'active-replace'])
            ->assertOk()
            ->assertJsonPath('data.source_financial_account.id', $replacement->id);
    }

    #[Test]
    public function removed_transfers_must_be_restored_before_editing(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user);
        $transfer = Transfer::factory()->removed()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
        ]);

        $this->patchJson("/api/v1/transfers/{$transfer->id}", ['description' => 'Bloqueada'], ['Idempotency-Key' => 'removed-edit'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'transfer_edit_requires_restore');
    }

    #[Test]
    public function retiming_an_effective_transfer_into_the_future_keeps_its_effect_and_reports_a_notice(): void
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

        $this->patchJson("/api/v1/transfers/{$transfer->id}", ['transfer_date' => today()->addWeek()->toDateString()], ['Idempotency-Key' => 'retime'])
            ->assertOk()
            ->assertJsonPath('data.status', 'effective')
            ->assertJsonPath('meta.notice.code', 'effective_future_date');

        self::assertSame(400_000, $source->refresh()->current_balance_centavos);
        self::assertSame(300_000, $destination->refresh()->current_balance_centavos);

        $this->patchJson("/api/v1/transfers/{$transfer->id}", ['status' => 'pending'], ['Idempotency-Key' => 'retime-pending'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
        self::assertSame(500_000, $source->refresh()->current_balance_centavos);
        self::assertSame(200_000, $destination->refresh()->current_balance_centavos);
    }

    #[Test]
    public function a_pending_future_transfer_cannot_be_marked_effective(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user, 500_000, 200_000);
        $transfer = Transfer::factory()->future()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 100_000,
        ]);

        $this->patchJson("/api/v1/transfers/{$transfer->id}", ['status' => 'effective'], ['Idempotency-Key' => 'future-effective'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'effective_future_date');

        self::assertSame('pending', $transfer->refresh()->status->value);
        self::assertSame(500_000, $source->refresh()->current_balance_centavos);
        self::assertSame(200_000, $destination->refresh()->current_balance_centavos);
    }

    #[Test]
    public function updates_validate_proposed_balances_before_changing_anything(): void
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

        $this->patchJson("/api/v1/transfers/{$transfer->id}", ['amount_centavos' => 500_001], ['Idempotency-Key' => 'overdraft-update'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'insufficient_source_balance');

        self::assertSame(400_000, $source->refresh()->current_balance_centavos);
        self::assertSame(300_000, $destination->refresh()->current_balance_centavos);
        self::assertSame(100_000, $transfer->refresh()->amount_centavos);
    }

    #[Test]
    public function reused_idempotency_keys_with_different_content_are_rejected(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user);
        $payload = $this->payload($source, $destination);

        $this->postJson('/api/v1/transfers', $payload, ['Idempotency-Key' => 'changed-content'])->assertCreated();
        $this->postJson('/api/v1/transfers', array_merge($payload, ['amount_centavos' => 1]), ['Idempotency-Key' => 'changed-content'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_key_reused');

        self::assertSame(1, Transfer::count());
    }
}
