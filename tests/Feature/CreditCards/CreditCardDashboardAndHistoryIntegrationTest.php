<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardDashboardAndHistoryIntegrationTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function dashboard_card_projection_separates_obligations_from_cash(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17, 'credit_limit_centavos' => 500_000]);
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 250_000, 'current_balance_centavos' => 250_000]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 60_000, 2, '2026-09-05');

        $response = $this->getJson('/api/v1/financial-dashboard/credit-cards')
            ->assertOk()
            ->assertJsonPath('data.outstanding_obligation.amount_centavos', 60_000)
            ->assertJsonPath('data.card_credit.amount_centavos', 0)
            ->assertJsonPath('data.available_credit.amount_centavos', 440_000)
            ->assertJsonCount(1, 'data.cards')
            ->assertJsonCount(1, 'data.upcoming_statements')
            ->assertJsonPath('data.upcoming_statements.0.outstanding_amount.amount_centavos', 30_000);

        // Cash position is untouched by card activity.
        $this->assertSame(250_000, $account->refresh()->current_balance_centavos);
        $this->getJson('/api/v1/financial-dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.current_total_balance.amount_centavos', 250_000)
            ->assertJsonPath('data.realized_expenses.amount_centavos', 0);

        $this->assertSame(60_000, $response->json('data.cards.0.summary.used_credit.amount_centavos'));
    }

    #[Test]
    public function dashboard_projection_reflects_payments_and_card_credit(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17, 'credit_limit_centavos' => 500_000]);
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 200_000, 'current_balance_centavos' => 200_000]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 20_000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;
        $this->payStatement($statement, $account, 20_000, '2026-08-15');

        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => 20_000,
            'event_date' => '2026-09-19',
        ], ['Idempotency-Key' => 'dashboard-refund'])->assertCreated();

        $this->getJson('/api/v1/financial-dashboard/credit-cards')
            ->assertOk()
            ->assertJsonPath('data.outstanding_obligation.amount_centavos', 0)
            ->assertJsonPath('data.card_credit.amount_centavos', 20_000)
            ->assertJsonPath('data.available_credit.amount_centavos', 520_000)
            ->assertJsonCount(0, 'data.upcoming_statements');
    }

    #[Test]
    public function dashboard_projection_excludes_archived_cards_from_cards_and_totals(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $active = $this->activeCard($user, ['name' => 'Ativo', 'credit_limit_centavos' => 100_000]);
        $this->archivedCard($user, ['name' => 'Arquivado', 'credit_limit_centavos' => 500_000]);

        $this->getJson('/api/v1/financial-dashboard/credit-cards')
            ->assertOk()
            ->assertJsonCount(1, 'data.cards')
            ->assertJsonPath('data.cards.0.id', $active->id)
            ->assertJsonPath('data.outstanding_obligation.amount_centavos', 0)
            ->assertJsonPath('data.card_credit.amount_centavos', 0)
            ->assertJsonPath('data.available_credit.amount_centavos', 100_000);
    }

    #[Test]
    public function recognized_card_expense_appears_once_in_financial_history(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $category = $this->expenseCategory($user, ['name' => 'Mercado']);
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 300_000, 'current_balance_centavos' => 300_000]);
        $purchase = $this->recordPurchase($card, $category, 90_000, 3, '2026-08-05');

        // Statements closing in August and September are already realized.
        $history = $this->getJson('/api/v1/financial-history')->assertOk();
        $cardEntries = collect($history->json('data'))->where('movement_kind', 'credit_card_expense');
        $this->assertCount(2, $cardEntries);
        $this->assertSame(30_000, $cardEntries->first()['amount_centavos']);
        $this->assertSame('2026-09-10', $cardEntries->first()['movement_date']);
        $this->assertSame('Mercado', $cardEntries->first()['category']['name']);
        $this->assertSame($card->id, $cardEntries->first()['credit_card']['id']);

        // Filtering by the discriminator returns only card expenses.
        $filtered = $this->getJson('/api/v1/financial-history?movement_kind=credit_card_expense')->assertOk();
        $this->assertSame(2, $filtered->json('meta.total'));
        $this->assertSame('credit_card_expense', $filtered->json('data.0.movement_kind'));

        // Settling the statement adds no cash-expense history entry.
        $this->postJson('/api/v1/credit-card-statements/'.$purchase->installments()->first()->credit_card_statement_id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 30_000,
            'payment_date' => '2026-08-15',
        ], ['Idempotency-Key' => 'history-payment'])->assertCreated();

        $afterPayment = $this->getJson('/api/v1/financial-history')->assertOk();
        $this->assertCount(2, collect($afterPayment->json('data'))->where('movement_kind', 'credit_card_expense'));
        $this->assertSame(0, Transaction::query()->where('user_id', $user->id)->count());
        $this->assertSame(270_000, $account->refresh()->current_balance_centavos);
        $this->assertSame(1, FinancialAccount::query()->whereKey($account->id)->count());
    }

    #[Test]
    public function history_excludes_other_users_card_expenses(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $other = User::factory()->create();
        $otherCard = $this->activeCard($other, ['closing_day' => 10, 'due_day' => 17]);
        $this->recordPurchase($otherCard, $this->expenseCategory($other), 50_000, 1, '2026-08-05');

        $this->getJson('/api/v1/financial-history')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->assertSame(0, CreditCard::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function guest_cannot_read_dashboard_card_projection(): void
    {
        $this->getJson('/api/v1/financial-dashboard/credit-cards')->assertUnauthorized();
    }
}
