<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialDashboard;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Models\Transaction;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\RecurringTransactions\RecurringTransactionFeatureTestCase;
use Tests\Support\FinancialDashboard\FinancialDashboardFixtures;

final class RecurringCardDashboardIntegrationTest extends RecurringTransactionFeatureTestCase
{
    use FinancialDashboardFixtures;
    use RefreshDatabase;

    #[Test]
    public function expected_card_charge_is_forecast_once_without_realized_spending(): void
    {
        $user = $this->dashboardSignIn('2026-09-17');
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'generation_mode' => CardGenerationMode::Confirmation,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-17',
            'eligibility_starts_on' => '2026-09-17',
            'schedule_cursor' => '2026-09-17',
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-17');

        $upcoming = $this->getJson('/api/v1/financial-dashboard/upcoming-activity')->assertOk();
        $items = collect($upcoming->json('data'))->where('source_kind', 'card_expectation');
        self::assertCount(1, $items);
        self::assertSame('2026-09-17', $items->first()['expected_date']);

        $period = '?preset=custom&from=2026-09-01&to=2026-09-30';
        $this->getJson('/api/v1/financial-dashboard/summary'.$period)->assertOk()
            ->assertJsonPath('data.realized_expenses.amount_centavos', 0);
        $this->getJson('/api/v1/financial-dashboard/expense-distribution'.$period)->assertOk()
            ->assertJsonPath('data.total_expenses.amount_centavos', 0);
        $this->getJson('/api/v1/financial-dashboard/recent-activity')->assertOk()->assertJsonPath('data', []);
        self::assertNull($rule->cardOccurrences()->firstOrFail()->purchase);
    }

    #[Test]
    public function recorded_card_purchase_flows_once_through_dashboard_history_and_payment(): void
    {
        $user = $this->dashboardSignIn('2026-09-20');
        $account = $this->ownedAccount($user, 100_000);
        $otherAccount = $this->ownedAccount($user, 50_000);
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-20');
        $occurrence = $rule->cardOccurrences()->firstOrFail();
        $purchase = $occurrence->purchase;
        $statement = $purchase->installments()->firstOrFail()->statement;

        $this->dashboardTransfer($user, [
            'source_financial_account_id' => $account->id,
            'destination_financial_account_id' => $otherAccount->id,
            'amount_centavos' => 5_000,
            'transfer_date' => '2026-09-19',
        ]);

        $period = '?preset=custom&from=2026-09-01&to=2026-09-30';
        $this->getJson('/api/v1/financial-dashboard/summary'.$period)->assertOk()
            ->assertJsonPath('data.realized_expenses.amount_centavos', 15_000)
            ->assertJsonPath('data.realized_income.amount_centavos', 0);
        $this->getJson('/api/v1/financial-dashboard/expense-distribution'.$period)->assertOk()
            ->assertJsonPath('data.total_expenses.amount_centavos', 15_000)
            ->assertJsonPath('data.categories.0.category.id', $category->id)
            ->assertJsonPath('data.categories.0.total.amount_centavos', 15_000);
        $this->getJson('/api/v1/financial-dashboard/evolution?preset=custom&from=2026-09-10&to=2026-09-10')->assertOk()
            ->assertJsonPath('data.intervals.0.expenses.amount_centavos', 15_000);

        $recent = $this->getJson('/api/v1/financial-dashboard/recent-activity')->assertOk();
        $cardItems = collect($recent->json('data'))->where('movement_kind', 'credit_card_expense');
        self::assertCount(1, $cardItems);
        self::assertSame($rule->id, $cardItems->first()['recurrence_source']['id']);
        $history = $this->getJson('/api/v1/financial-history')->assertOk();
        self::assertCount(1, collect($history->json('data'))->where('movement_kind', 'credit_card_expense'));
        self::assertSame(0, Transaction::query()->where('recurring_transaction_id', $rule->id)->count());

        $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 15_000,
            'payment_date' => '2026-09-17',
        ], ['Idempotency-Key' => 'dashboard-recurring-payment'])->assertCreated();
        $this->getJson('/api/v1/financial-dashboard/summary'.$period)->assertOk()
            ->assertJsonPath('data.realized_expenses.amount_centavos', 15_000);
        self::assertCount(1, collect($this->getJson('/api/v1/financial-history')->assertOk()->json('data'))
            ->where('movement_kind', 'credit_card_expense'));
    }

    #[Test]
    public function open_card_purchase_is_recent_but_not_yet_realized(): void
    {
        $user = $this->dashboardSignIn('2026-09-18');
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-17',
            'eligibility_starts_on' => '2026-09-17',
            'schedule_cursor' => '2026-09-17',
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-17');

        $recent = $this->getJson('/api/v1/financial-dashboard/recent-activity')->assertOk();
        self::assertCount(1, collect($recent->json('data'))->where('movement_kind', 'credit_card_expense'));
        $this->getJson('/api/v1/financial-dashboard/summary?preset=custom&from=2026-09-01&to=2026-10-31')->assertOk()
            ->assertJsonPath('data.realized_expenses.amount_centavos', 0);
        $upcoming = $this->getJson('/api/v1/financial-dashboard/upcoming-activity')->assertOk();
        self::assertCount(1, collect($upcoming->json('data'))->where('source_kind', 'card_expectation'));
        self::assertSame('2026-10-17', collect($upcoming->json('data'))->where('source_kind', 'card_expectation')->first()['expected_date']);
    }
}
