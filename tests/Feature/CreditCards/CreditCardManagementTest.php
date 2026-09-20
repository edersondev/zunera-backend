<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Data\CreditCards\CreateCreditEventData;
use App\Enums\CreditCards\CreditCardCreditEventReason;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\User;
use App\Services\CreditCards\CreditCardCreditEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardManagementTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function owner_creates_a_card_with_derived_summary_and_current_statement(): void
    {
        $user = $this->cardSignIn();

        $response = $this->postJson('/api/v1/credit-cards', $this->payload(), ['Idempotency-Key' => 'card-create-1'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.closing_day', 10)
            ->assertJsonPath('data.due_day', 17)
            ->assertJsonPath('data.summary.credit_limit.amount_centavos', 500_000)
            ->assertJsonPath('data.summary.used_credit.amount_centavos', 0)
            ->assertJsonPath('data.summary.card_credit.amount_centavos', 0)
            ->assertJsonPath('data.summary.available_credit.amount_centavos', 500_000)
            ->assertJsonPath('data.summary.is_over_limit', false)
            ->assertJsonPath('data.current_statement.original_amount.amount_centavos', 0)
            ->assertJsonPath('data.current_statement.status', 'open')
            ->assertJsonPath('data.current_statement.is_current', true)
            ->assertJsonPath('data.current_statement.closing_date', '2026-10-10')
            ->assertJsonPath('data.current_statement.due_date', '2026-10-17');

        $this->assertSame(1, CreditCard::count());
        $this->assertArrayNotHasKey('card_number', $response->json('data'));
        $this->assertArrayNotHasKey('cvv', $response->json('data'));
    }

    #[Test]
    public function repeated_create_submission_replays_the_original_response(): void
    {
        $this->cardSignIn();

        $first = $this->postJson('/api/v1/credit-cards', $this->payload(), ['Idempotency-Key' => 'card-replay'])
            ->assertCreated();

        $this->postJson('/api/v1/credit-cards', $this->payload(), ['Idempotency-Key' => 'card-replay'])
            ->assertCreated()
            ->assertExactJson($first->json());

        $this->assertSame(1, CreditCard::count());
    }

    #[Test]
    public function reuse_of_a_key_for_a_different_payload_is_rejected(): void
    {
        $this->cardSignIn();

        $this->postJson('/api/v1/credit-cards', $this->payload(), ['Idempotency-Key' => 'card-conflict'])->assertCreated();

        $this->postJson('/api/v1/credit-cards', $this->payload(['name' => 'Outro']), ['Idempotency-Key' => 'card-conflict'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_key_reused');
    }

    #[Test]
    public function list_returns_active_cards_by_default_and_archived_on_request(): void
    {
        $user = $this->cardSignIn();
        $active = $this->activeCard($user, ['name' => 'Ativo']);
        $archived = $this->archivedCard($user, ['name' => 'Arquivado']);

        $this->getJson('/api/v1/credit-cards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        $this->getJson('/api/v1/credit-cards?view=archived')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $archived->id)
            ->assertJsonPath('data.0.status', 'archived');
    }

    #[Test]
    public function owner_updates_descriptive_fields_and_billing_days_without_touching_existing_statements(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $category = $this->expenseCategory($user);
        $purchase = $this->recordPurchase($card, $category, 30_000, 3, '2026-09-05');

        $statementDates = CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->orderBy('closing_date')
            ->pluck('closing_date')
            ->map(fn ($date) => (string) $date)
            ->all();
        $installmentAssignments = $purchase->installments()->pluck('credit_card_statement_id')->all();

        $this->patchJson('/api/v1/credit-cards/'.$card->id, [
            'name' => 'Cartão renomeado',
            'closing_day' => 25,
            'due_day' => 5,
        ], ['Idempotency-Key' => 'card-update-1'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cartão renomeado')
            ->assertJsonPath('data.closing_day', 25)
            ->assertJsonPath('data.due_day', 5);

        $this->assertSame($statementDates, CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->orderBy('closing_date')
            ->pluck('closing_date')
            ->map(fn ($date) => (string) $date)
            ->all());
        $this->assertSame($installmentAssignments, $purchase->refresh()->installments()->pluck('credit_card_statement_id')->all());

        // Later purchases follow the updated billing days.
        $later = $this->recordPurchase($card->refresh(), $category, 10_000, 1, '2026-09-26');
        $this->assertSame('2026-10-25', $later->installments()->first()->statement->closing_date->toDateString());
    }

    #[Test]
    public function archive_is_blocked_while_an_obligation_remains(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user);
        $this->recordPurchase($card, $this->expenseCategory($user), 20_000, 1, '2026-09-05');

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/archive', [], ['Idempotency-Key' => 'archive-blocked'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'archive_blocked_by_outstanding');

        $this->assertSame('active', $card->refresh()->status->value);
    }

    #[Test]
    public function archive_is_blocked_while_card_credit_remains(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user);
        $category = $this->expenseCategory($user);
        $purchase = $this->recordPurchase($card, $category, 20_000, 1, '2026-08-05');
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);
        $this->payStatement($purchase->installments()->first()->statement, $account, 20_000);

        app(CreditCardCreditEventService::class)
            ->record($user, $purchase, new CreateCreditEventData(
                userId: $user->id,
                reason: CreditCardCreditEventReason::Refund,
                amountCentavos: 20_000,
                eventDate: '2026-09-18',
                notes: null,
            ), 'credit-event-archive');

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/archive', [], ['Idempotency-Key' => 'archive-credit'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'archive_blocked_by_card_credit');

        $this->assertSame('active', $card->refresh()->status->value);
    }

    #[Test]
    public function archive_succeeds_with_zero_obligation_and_zero_credit_and_keeps_history(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user);
        $history = $this->recordPurchase($card, $this->expenseCategory($user), 10_000, 1, '2026-08-05');
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 50_000, 'current_balance_centavos' => 50_000]);
        $this->payStatement($history->installments()->first()->statement, $account, 10_000);

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/archive', [], ['Idempotency-Key' => 'archive-ok'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->assertNotNull($card->refresh()->archived_at);
        $this->assertSame(1, $history->installments()->count());
        $this->assertSame('archived', $this->getJson('/api/v1/credit-cards/'.$card->id)->json('data.status'));
    }

    #[Test]
    public function foreign_cards_are_invisible_for_read_and_mutation(): void
    {
        $this->cardSignIn();
        $foreignOwner = User::factory()->create();
        $foreign = $this->activeCard($foreignOwner);

        $this->getJson('/api/v1/credit-cards/'.$foreign->id)->assertNotFound();
        $this->patchJson('/api/v1/credit-cards/'.$foreign->id, ['name' => 'Invasão'], ['Idempotency-Key' => 'foreign-update'])->assertNotFound();
        $this->postJson('/api/v1/credit-cards/'.$foreign->id.'/archive', [], ['Idempotency-Key' => 'foreign-archive'])->assertNotFound();
        $this->getJson('/api/v1/credit-cards')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function guest_cannot_reach_card_routes(): void
    {
        $this->getJson('/api/v1/credit-cards')->assertUnauthorized();
        $this->postJson('/api/v1/credit-cards', $this->payload(), ['Idempotency-Key' => 'guest'])->assertUnauthorized();
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nubank',
            'institution_name' => 'Nubank',
            'last_four' => '1234',
            'color' => 'violet',
            'icon' => 'credit_card',
            'credit_limit_centavos' => 500_000,
            'closing_day' => 10,
            'due_day' => 17,
        ], $overrides);
    }
}
