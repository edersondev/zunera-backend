<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Data\CreditCards\CreateCreditEventData;
use App\Enums\CreditCards\CreditCardCreditEventReason;
use App\Models\CreditCardStatement;
use App\Models\User;
use App\Services\CreditCards\BillingCycleCalculator;
use App\Services\CreditCards\CreditCardCreditEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardStatementTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function active_card_without_activity_exposes_a_zero_current_statement(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);

        $this->getJson('/api/v1/credit-cards/'.$card->id)
            ->assertOk()
            ->assertJsonPath('data.current_statement.id', null)
            ->assertJsonPath('data.current_statement.status', 'open')
            ->assertJsonPath('data.current_statement.is_current', true)
            ->assertJsonPath('data.current_statement.original_amount.amount_centavos', 0)
            ->assertJsonPath('data.current_statement.period_from', '2026-09-11')
            ->assertJsonPath('data.current_statement.closing_date', '2026-10-10')
            ->assertJsonPath('data.current_statement.due_date', '2026-10-17');

        $this->assertSame(0, CreditCardStatement::count());

        $this->getJson('/api/v1/credit-cards/'.$card->id.'/statements')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function statement_stays_open_through_the_closing_day_then_closes_and_becomes_overdue(): void
    {
        $user = $this->cardSignIn('2026-09-05');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $this->recordPurchase($card, $this->expenseCategory($user), 30_000, 1, '2026-09-05');
        $statement = CreditCardStatement::query()->firstOrFail();

        $this->cardSignIn('2026-09-10', $user);
        $this->getJson('/api/v1/credit-card-statements/'.$statement->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.is_current', true)
            ->assertJsonPath('data.installments.0.is_directly_editable', true);

        $this->cardSignIn('2026-09-11', $user);
        $this->getJson('/api/v1/credit-card-statements/'.$statement->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.is_current', false)
            ->assertJsonPath('data.installments.0.is_directly_editable', false)
            ->assertJsonPath('data.outstanding_amount.amount_centavos', 30_000)
            ->assertJsonPath('data.net_amount.amount_centavos', 30_000);

        $this->cardSignIn('2026-09-18', $user);
        $this->getJson('/api/v1/credit-card-statements/'.$statement->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'overdue');

        $this->assertNotNull($statement->refresh()->finalized_at);
    }

    #[Test]
    public function statement_detail_exposes_installments_payments_and_credit_events(): void
    {
        $user = $this->cardSignIn('2026-09-16');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $category = $this->expenseCategory($user);
        $purchase = $this->recordPurchase($card, $category, 60_000, 2, '2026-09-05');
        $statement = CreditCardStatement::query()->orderBy('closing_date')->firstOrFail();
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 500_000, 'current_balance_centavos' => 500_000]);
        $this->payStatement($statement, $account, 10_000, '2026-09-15');

        app(CreditCardCreditEventService::class)->record(
            $user,
            $purchase,
            new CreateCreditEventData(
                userId: $user->id,
                reason: CreditCardCreditEventReason::Correction,
                amountCentavos: 5_000,
                eventDate: '2026-09-16',
                notes: 'Correção de valor',
            ),
            'statement-detail-event',
        );

        $response = $this->getJson('/api/v1/credit-card-statements/'.$statement->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.original_amount.amount_centavos', 30_000)
            ->assertJsonPath('data.credit_adjustments.amount_centavos', 5_000)
            ->assertJsonPath('data.net_amount.amount_centavos', 25_000)
            ->assertJsonPath('data.paid_amount.amount_centavos', 10_000)
            ->assertJsonPath('data.outstanding_amount.amount_centavos', 15_000)
            ->assertJsonCount(1, 'data.installments')
            ->assertJsonPath('data.installments.0.description', 'Compra teste')
            ->assertJsonPath('data.installments.0.purchase_date', '2026-09-05')
            ->assertJsonPath('data.installments.0.purchase_id', $purchase->id)
            ->assertJsonPath('data.installments.0.category.id', $category->id)
            ->assertJsonPath('data.installments.0.category.name', $category->name)
            ->assertJsonPath('data.installments.0.category.icon', $category->icon)
            ->assertJsonPath('data.installments.0.category.color', $category->color)
            ->assertJsonPath('data.installments.0.purchase_total_amount.amount_centavos', 60_000)
            ->assertJsonPath('data.installments.0.amount.amount_centavos', 30_000)
            ->assertJsonPath('data.installments.0.credit_adjustment.amount_centavos', 5_000)
            ->assertJsonPath('data.installments.0.recognized_amount.amount_centavos', 25_000)
            ->assertJsonPath('data.installments.0.is_directly_editable', false)
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonCount(1, 'data.credit_events');

        $this->assertSame('Correção de valor', $response->json('data.credit_events.0.notes'));
    }

    #[Test]
    public function statement_list_filters_by_status_and_paginates(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $category = $this->expenseCategory($user);
        $this->recordPurchase($card, $category, 30_000, 3, '2026-09-05');

        $this->getJson('/api/v1/credit-cards/'.$card->id.'/statements')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/v1/credit-cards/'.$card->id.'/statements?status=open')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/credit-cards/'.$card->id.'/statements?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/v1/credit-cards/'.$card->id.'/statements?status=paid')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function foreign_statements_and_cards_are_invisible(): void
    {
        $this->cardSignIn('2026-09-20');
        $other = User::factory()->create();
        $foreignCard = $this->activeCard($other);
        $foreignStatement = $this->statementFor($foreignCard, app(BillingCycleCalculator::class)->cycleForDate('2026-09-05', $foreignCard->closing_day, $foreignCard->due_day));

        $this->getJson('/api/v1/credit-card-statements/'.$foreignStatement->id)->assertNotFound();
        $this->getJson('/api/v1/credit-cards/'.$foreignCard->id.'/statements')->assertNotFound();
    }

    #[Test]
    public function archived_cards_keep_history_readable_without_gaining_a_current_statement(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 10_000, 1, '2026-08-05');
        $account = $this->cardAccount($user);
        $this->payStatement($purchase->installments()->first()->statement, $account, 10_000);

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/archive', [], ['Idempotency-Key' => 'archive-history'])->assertOk();

        $this->getJson('/api/v1/credit-cards/'.$card->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.current_statement.is_current', false);

        $this->getJson('/api/v1/credit-cards/'.$card->id.'/statements')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }
}
