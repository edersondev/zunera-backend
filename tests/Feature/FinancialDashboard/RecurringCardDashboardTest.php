<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialDashboard;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Models\CreditCard;
use App\Models\RecurringCardOccurrence;
use App\Models\RecurringTransaction;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialDashboard\FinancialDashboardFixtures;
use Tests\TestCase;

final class RecurringCardDashboardTest extends TestCase
{
    use FinancialDashboardFixtures;
    use RefreshDatabase;

    #[Test]
    public function future_card_rule_dates_project_once_with_no_realized_cash_effect(): void
    {
        $user = $this->dashboardSignIn();
        $card = CreditCard::factory()->create(['user_id' => $user->id, 'name' => 'C6 Bank']);
        $category = $this->dashboardCategory($user, 'expense', ['name' => 'Academia']);

        RecurringTransaction::factory()->card($card)->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => '2026-09-20',
            'eligibility_starts_on' => '2026-09-20',
            'schedule_cursor' => '2026-09-20',
            'state' => RecurrenceState::Active,
        ]);

        $this->getJson('/api/v1/financial-dashboard/upcoming-activity')
            ->assertOk()
            ->assertJsonPath('data.0.source_kind', 'card_expectation')
            ->assertJsonPath('data.0.expected_date', '2026-09-20')
            ->assertJsonPath('data.0.destination_type', 'credit_card')
            ->assertJsonPath('data.0.credit_card.name', 'C6 Bank');

        $this->getJson('/api/v1/financial-dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.realized_expenses.amount_centavos', 0);
    }

    #[Test]
    public function a_recorded_card_occurrence_is_suppressed_from_upcoming_and_counted_once_in_history(): void
    {
        $user = $this->dashboardSignIn();
        $card = CreditCard::factory()->create(['user_id' => $user->id, 'name' => 'C6 Bank']);
        $category = $this->dashboardCategory($user, 'expense', ['name' => 'Academia']);

        $rule = RecurringTransaction::factory()->card($card)->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
            'state' => RecurrenceState::Active,
        ]);
        app(RecurringOccurrenceService::class)->processDueRules('2026-09-17');

        $occurrence = RecurringCardOccurrence::query()->where('recurring_transaction_id', $rule->id)->firstOrFail();
        self::assertSame(CardOccurrenceState::Recorded, $occurrence->state);

        // The recorded date is not re-projected; only the next eligible month is.
        $upcoming = $this->getJson('/api/v1/financial-dashboard/upcoming-activity')->assertOk();
        $cardItems = collect($upcoming->json('data'))->where('source_kind', 'card_expectation');
        self::assertCount(1, $cardItems);
        self::assertSame('2026-10-01', $cardItems->first()['expected_date']);

        $history = $this->getJson('/api/v1/financial-history')->assertOk();
        $cardExpenses = collect($history->json('data'))->where('movement_kind', 'credit_card_expense');
        self::assertCount(1, $cardExpenses);
    }

    #[Test]
    public function a_confirmation_expected_occurrence_has_no_budget_or_dashboard_expense(): void
    {
        $user = $this->dashboardSignIn();
        $card = CreditCard::factory()->create(['user_id' => $user->id, 'name' => 'C6 Bank']);
        $category = $this->dashboardCategory($user, 'expense', ['name' => 'Academia']);

        $rule = RecurringTransaction::factory()->card($card, CardGenerationMode::Confirmation)->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
            'state' => RecurrenceState::Active,
        ]);
        app(RecurringOccurrenceService::class)->processDueRules('2026-09-17');

        $occurrence = RecurringCardOccurrence::query()->firstOrFail();
        self::assertSame(CardOccurrenceState::Expected, $occurrence->state);
        self::assertSame(0, $card->purchases()->count());

        $history = $this->getJson('/api/v1/financial-history')->assertOk();
        self::assertCount(0, collect($history->json('data'))->where('movement_kind', 'credit_card_expense'));
    }
}
