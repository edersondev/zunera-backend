<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\CreditCardCreditEvent;
use App\Models\CreditCardInstallment;
use App\Models\CreditCardStatement;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardRefundAndCreditEventTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function full_refund_of_an_unpaid_statement_clears_the_obligation_without_cash_movement(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17, 'credit_limit_centavos' => 500_000]);
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 30_000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;

        $response = $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => 30_000,
            'event_date' => '2026-09-19',
            'notes' => 'Estorno do lojista',
        ], ['Idempotency-Key' => 'refund-full'])
            ->assertCreated()
            ->assertJsonPath('data.credit_event.reason', 'refund')
            ->assertJsonPath('data.credit_event.amount.amount_centavos', 30_000)
            ->assertJsonCount(1, 'data.affected_statements')
            ->assertJsonPath('data.affected_statements.0.outstanding_amount.amount_centavos', 0)
            ->assertJsonPath('data.affected_statements.0.status', 'paid');

        $this->assertSame(0, $this->cardSummary($card->refresh())['used_credit_centavos']);
        $this->assertSame(0, $this->cardSummary($card->refresh())['card_credit_centavos']);
        $this->assertSame(100_000, $account->refresh()->current_balance_centavos);
        $this->assertSame(0, Transaction::count());

        // Original purchase and statement history stay intact.
        $this->assertSame(1, CreditCardInstallment::query()->where('credit_card_purchase_id', $purchase->id)->count());
        $this->assertSame('refund', $response->json('data.credit_event.reason'));
    }

    #[Test]
    public function partial_refund_reduces_only_the_refunded_amount(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 30_000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;

        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'correction',
            'amount_centavos' => 12_000,
            'event_date' => '2026-09-19',
        ], ['Idempotency-Key' => 'refund-partial'])
            ->assertCreated()
            ->assertJsonPath('data.affected_statements.0.credit_adjustments.amount_centavos', 12_000)
            ->assertJsonPath('data.affected_statements.0.net_amount.amount_centavos', 18_000)
            ->assertJsonPath('data.affected_statements.0.outstanding_amount.amount_centavos', 18_000);

        $this->assertSame(18_000, $this->cardSummary($card->refresh())['used_credit_centavos']);
    }

    #[Test]
    public function refund_after_full_payment_becomes_reusable_card_credit(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17, 'credit_limit_centavos' => 500_000]);
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 200_000, 'current_balance_centavos' => 200_000]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 20_000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;
        $this->payStatement($statement, $account, 20_000, '2026-08-15');

        $this->assertSame(0, $this->cardSummary($card->refresh())['used_credit_centavos']);

        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => 20_000,
            'event_date' => '2026-09-19',
        ], ['Idempotency-Key' => 'refund-after-payment'])->assertCreated();

        $summary = $this->cardSummary($card->refresh());
        $this->assertSame(0, $summary['used_credit_centavos']);
        $this->assertSame(20_000, $summary['card_credit_centavos']);
        $this->assertSame(520_000, $summary['available_credit_centavos']);
        $this->assertSame(180_000, $account->refresh()->current_balance_centavos);
    }

    #[Test]
    public function card_credit_automatically_settles_the_oldest_unpaid_statement(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17, 'credit_limit_centavos' => 900_000]);
        $category = $this->expenseCategory($user);
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 500_000, 'current_balance_centavos' => 500_000]);

        $refunded = $this->recordPurchase($card, $category, 20_000, 1, '2026-06-05');
        $this->payStatement($refunded->installments()->first()->statement, $account, 20_000, '2026-06-15');
        $balanceAfterPayment = $account->refresh()->current_balance_centavos;
        $oldest = $this->recordPurchase($card, $category, 15_000, 1, '2026-07-05');
        $newer = $this->recordPurchase($card, $category, 10_000, 1, '2026-08-05');

        $this->postJson('/api/v1/credit-card-purchases/'.$refunded->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => 20_000,
            'event_date' => '2026-09-19',
        ], ['Idempotency-Key' => 'credit-auto-apply'])->assertCreated();

        $this->assertSame(0, $this->cardSummary($card->refresh())['card_credit_centavos'], 'Card credit is fully applied.');
        $oldestStatement = $oldest->installments()->first()->statement->refresh();
        $newerStatement = $newer->installments()->first()->statement->refresh();
        $this->assertSame(0, $oldestStatement->outstandingCentavos(), 'Oldest unpaid statement is settled first.');
        $this->assertSame(5_000, $newerStatement->outstandingCentavos(), 'Remainder applies to the next statement.');
        $this->assertSame($balanceAfterPayment, $account->refresh()->current_balance_centavos, 'Credit application never moves cash.');
    }

    #[Test]
    public function cancellation_records_a_full_traceable_event_and_keeps_the_purchase(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 36_000, 3, '2026-09-15');

        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'cancellation',
            'amount_centavos' => 36_000,
            'event_date' => '2026-09-19',
        ], ['Idempotency-Key' => 'cancellation'])
            ->assertCreated()
            ->assertJsonPath('data.credit_event.reason', 'cancellation')
            ->assertJsonCount(3, 'data.credit_event.applications');

        $this->assertSame(0, $this->cardSummary($card->refresh())['used_credit_centavos']);
        $this->assertSame(1, CreditCardCreditEvent::count());
        $this->assertSame(3, CreditCardInstallment::query()->where('credit_card_purchase_id', $purchase->id)->count());
        $this->getJson('/api/v1/credit-card-purchases/'.$purchase->id)
            ->assertOk()
            ->assertJsonPath('data.installments.0.recognized_amount.amount_centavos', 0);
    }

    #[Test]
    public function credit_event_cannot_exceed_the_uncredited_amount(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 20_000, 1, '2026-08-05');

        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => 20_000,
            'event_date' => '2026-09-19',
        ], ['Idempotency-Key' => 'refund-first'])->assertCreated();

        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => 1,
            'event_date' => '2026-09-19',
        ], ['Idempotency-Key' => 'refund-second'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'credit_event_exceeds_uncredited');

        $this->assertSame(1, CreditCardCreditEvent::count());
    }

    #[Test]
    public function credit_event_replays_and_rejects_invalid_input(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 20_000, 1, '2026-08-05');
        $payload = ['reason' => 'refund', 'amount_centavos' => 5_000, 'event_date' => '2026-09-19'];

        $first = $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', $payload, ['Idempotency-Key' => 'event-replay'])
            ->assertCreated();
        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', $payload, ['Idempotency-Key' => 'event-replay'])
            ->assertCreated()
            ->assertExactJson($first->json());

        $this->assertSame(1, CreditCardCreditEvent::count());

        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'gift',
            'amount_centavos' => 0,
            'event_date' => '2026-09-19',
        ], ['Idempotency-Key' => 'event-invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason', 'amount_centavos']);

        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => 1_000,
            'event_date' => '2026-02-30',
        ], ['Idempotency-Key' => 'event-invalid-date'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_date');
    }

    #[Test]
    public function foreign_purchases_cannot_receive_credit_events(): void
    {
        $this->cardSignIn('2026-09-20');
        $other = User::factory()->create();
        $foreign = $this->recordPurchase($this->activeCard($other), $this->expenseCategory($other), 10_000, 1, '2026-08-05');

        $this->postJson('/api/v1/credit-card-purchases/'.$foreign->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => 1_000,
            'event_date' => '2026-09-19',
        ], ['Idempotency-Key' => 'event-foreign'])->assertNotFound();

        $this->assertSame(0, CreditCardCreditEvent::count());
    }

    #[Test]
    public function refunded_obligation_still_blocks_archiving_until_card_credit_is_resolved(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 10_000, 1, '2026-08-05');
        $this->payStatement($purchase->installments()->first()->statement, $account, 10_000, '2026-08-15');

        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => 10_000,
            'event_date' => '2026-09-19',
        ], ['Idempotency-Key' => 'refund-archive'])->assertCreated();

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/archive', [], ['Idempotency-Key' => 'archive-after-refund'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'archive_blocked_by_card_credit');

        $this->assertSame(1, FinancialAccount::query()->whereKey($account->id)->count());
        $this->assertSame(1, CreditCardStatement::query()->where('credit_card_id', $card->id)->count());
    }
}
