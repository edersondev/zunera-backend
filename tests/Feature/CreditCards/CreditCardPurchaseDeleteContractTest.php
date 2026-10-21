<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\CreditCardPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardPurchaseDeleteContractTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function owner_deletes_once_and_replays_only_a_minimal_receipt(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user);
        $category = $this->expenseCategory($user);
        $payload = [
            'category_id' => $category->id,
            'description' => 'Private merchant',
            'purchase_date' => '2026-09-15',
            'total_amount_centavos' => 3_000,
            'installment_count' => 1,
        ];
        $created = $this->postJson("/api/v1/credit-cards/{$card->id}/purchases", $payload, ['Idempotency-Key' => 'delete-old-create'])->assertCreated();
        $purchaseId = $created->json('data.id');

        $this->deleteJson("/api/v1/credit-card-purchases/{$purchaseId}")->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $first = $this->deleteJson("/api/v1/credit-card-purchases/{$purchaseId}", [], ['Idempotency-Key' => 'delete-first'])
            ->assertOk()->assertJsonPath('data.deleted_purchase_id', $purchaseId)
            ->assertJsonPath('data.affected_statement_closing_dates.0', '2026-10-10');
        self::assertSame(['deleted_purchase_id', 'affected_statement_closing_dates'], array_keys($first->json('data')));
        self::assertSame(0, CreditCardPurchase::query()->whereKey($purchaseId)->count());

        $this->deleteJson("/api/v1/credit-card-purchases/{$purchaseId}", [], ['Idempotency-Key' => 'delete-first'])
            ->assertOk()->assertExactJson($first->json());
        $this->deleteJson("/api/v1/credit-card-purchases/{$purchaseId}", [], ['Idempotency-Key' => 'delete-new'])->assertNotFound();
        $old = $this->postJson("/api/v1/credit-cards/{$card->id}/purchases", $payload, ['Idempotency-Key' => 'delete-old-create'])
            ->assertStatus(409)->assertJsonPath('code', 'PURCHASE_DELETED');
        self::assertStringNotContainsString('Private merchant', $old->getContent());
    }

    #[Test]
    public function linked_credit_and_foreign_purchase_block_deletion(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $purchase = $this->recordPurchase($this->activeCard($user), $this->expenseCategory($user), 4_000, 1, '2026-09-15');
        $this->recordCreditEvent($purchase, 1_000);
        $this->deleteJson("/api/v1/credit-card-purchases/{$purchase->id}", [], ['Idempotency-Key' => 'delete-linked'])
            ->assertStatus(409)->assertJsonPath('code', 'PURCHASE_HAS_LINKED_CREDITS');
        self::assertNotNull($purchase->fresh());

        $other = User::factory()->create();
        $foreign = $this->recordPurchase($this->activeCard($other), $this->expenseCategory($other), 2_000, 1, '2026-09-15');
        $this->deleteJson("/api/v1/credit-card-purchases/{$foreign->id}", [], ['Idempotency-Key' => 'delete-foreign'])->assertNotFound();
        self::assertNotNull($foreign->fresh());
    }

    #[Test]
    public function old_move_update_and_recurring_snapshots_cannot_replay_deleted_purchase_data(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 5_000, 1, '2026-09-15');
        $choice = ['mode' => 'full_schedule', 'target_closing_date' => '2026-11-10'];
        $preview = $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move-preview", $choice)->assertOk();
        $moveBody = [...$choice, 'preview_token' => $preview->json('data.preview_token')];
        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", $moveBody, ['Idempotency-Key' => 'old-move'])
            ->assertOk();
        $updateBody = ['description' => 'Sensitive description'];
        $this->patchJson("/api/v1/credit-card-purchases/{$purchase->id}", $updateBody, ['Idempotency-Key' => 'old-update'])
            ->assertOk();
        DB::table('recurring_mutation_requests')->insert([
            'user_id' => $user->id,
            'idempotency_key' => 'old-recurring',
            'operation' => 'update',
            'request_fingerprint' => str_repeat('a', 64),
            'response_status' => 200,
            'response_body' => json_encode(['data' => ['purchase_id' => $purchase->id, 'description' => 'Sensitive description']], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/v1/credit-card-purchases/{$purchase->id}", [], ['Idempotency-Key' => 'delete-after-move'])
            ->assertOk();
        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", $moveBody, ['Idempotency-Key' => 'old-move'])
            ->assertStatus(409)->assertJsonPath('code', 'PURCHASE_DELETED');
        $this->patchJson("/api/v1/credit-card-purchases/{$purchase->id}", $updateBody, ['Idempotency-Key' => 'old-update'])
            ->assertStatus(409)->assertJsonPath('code', 'PURCHASE_DELETED');

        foreach (['old-move', 'old-update'] as $key) {
            $snapshot = DB::table('credit_card_mutation_requests')->where('idempotency_key', $key)->value('response_body');
            self::assertSame(['code' => 'PURCHASE_DELETED'], json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR));
        }
        $recurringSnapshot = DB::table('recurring_mutation_requests')->where('idempotency_key', 'old-recurring')->value('response_body');
        self::assertSame(['code' => 'PURCHASE_DELETED'], json_decode($recurringSnapshot, true, flags: JSON_THROW_ON_ERROR));
    }
}
