<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\CreditCardPurchaseMove;
use App\Models\CreditCardStatement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardPurchaseMoveContractTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function owner_previews_confirms_and_replays_a_full_schedule_move(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 10_001, 2, '2026-09-15');
        $choice = ['mode' => 'full_schedule', 'target_closing_date' => '2026-12-10'];

        $preview = $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move-preview", $choice)
            ->assertOk()
            ->assertJsonCount(2, 'data.affected_installments')
            ->assertJsonPath('data.affected_installments.0.destination.closing_date', '2026-12-10');

        $body = [...$choice, 'preview_token' => $preview->json('data.preview_token')];
        $first = $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", $body, ['Idempotency-Key' => 'move-full-1'])
            ->assertOk()
            ->assertJsonCount(2, 'data.moves');
        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", $body, ['Idempotency-Key' => 'move-full-1'])
            ->assertOk()
            ->assertExactJson($first->json());

        self::assertSame(2, CreditCardPurchaseMove::query()->where('credit_card_purchase_id', $purchase->id)->count());
        self::assertSame(['2026-12-10', '2027-01-10'], $purchase->installments()->with('statement')->orderBy('sequence')->get()
            ->map(fn ($installment) => $installment->statement->closing_date->toDateString())->all());
        $this->getJson("/api/v1/credit-card-purchases/{$purchase->id}/moves")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function individual_move_can_share_a_statement_but_cannot_reverse_sequence(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 9_000, 3, '2026-09-15');
        $installments = $purchase->installments()->orderBy('sequence')->get();
        $choice = [
            'mode' => 'one_installment',
            'installment_id' => $installments[0]->id,
            'target_closing_date' => '2026-11-10',
        ];
        $preview = $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move-preview", $choice)
            ->assertOk()
            ->assertJsonCount(1, 'data.affected_installments');
        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", [
            ...$choice,
            'preview_token' => $preview->json('data.preview_token'),
        ], ['Idempotency-Key' => 'move-one-1'])->assertOk();

        self::assertSame(['2026-11-10', '2026-11-10', '2026-12-10'], $purchase->installments()->with('statement')->orderBy('sequence')->get()
            ->map(fn ($installment) => $installment->statement->closing_date->toDateString())->all());

        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move-preview", [
            'mode' => 'one_installment',
            'installment_id' => $installments[2]->id,
            'target_closing_date' => '2026-10-10',
        ])->assertStatus(422);
    }

    #[Test]
    public function move_contract_rejects_missing_key_foreign_purchase_and_stale_preview(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 5_000, 1, '2026-09-15');
        $choice = ['mode' => 'full_schedule', 'target_closing_date' => '2026-12-10'];
        $preview = $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move-preview", $choice)->assertOk();
        $body = [...$choice, 'preview_token' => $preview->json('data.preview_token')];

        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $purchase->update(['description' => 'Changed after preview']);
        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", $body, ['Idempotency-Key' => 'stale-move'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'STALE_MOVE_PREVIEW');

        $other = User::factory()->create();
        $foreign = $this->recordPurchase($this->activeCard($other), $this->expenseCategory($other), 2_000, 1, '2026-09-15');
        $this->postJson("/api/v1/credit-card-purchases/{$foreign->id}/move-preview", $choice)->assertNotFound();
        self::assertSame('2026-10-10', $purchase->installments()->firstOrFail()->statement->closing_date->toDateString());
    }

    #[Test]
    public function preview_rejects_invalid_choices_and_unrepresentable_full_schedule_without_writes(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 4_000, 2, '2026-09-15');
        $before = CreditCardStatement::count();
        $uri = "/api/v1/credit-card-purchases/{$purchase->id}/move-preview";

        foreach ([
            [['mode' => 'invalid', 'target_closing_date' => '2026-12-10'], 'mode'],
            [['mode' => 'full_schedule', 'target_closing_date' => '2026-12-11'], 'target_closing_date'],
            [['mode' => 'full_schedule', 'target_closing_date' => '2026-09-10'], 'target_closing_date'],
            [['mode' => 'one_installment', 'target_closing_date' => '2026-12-10'], 'installment_id'],
            [['mode' => 'one_installment', 'installment_id' => 999_999, 'target_closing_date' => '2026-12-10'], 'installment_id'],
            [['mode' => 'full_schedule', 'target_closing_date' => '9999-12-10'], 'target_closing_date'],
        ] as [$body, $field]) {
            $this->postJson($uri, $body)->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        self::assertSame($before, CreditCardStatement::count());
        self::assertSame(0, CreditCardPurchaseMove::count());
    }
}
