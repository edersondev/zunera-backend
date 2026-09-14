<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionType;
use App\Models\RecurringTransaction;
use App\Services\RecurringTransactions\RecurringDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class CreateAndListRecurringTransactionsTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_creates_a_monthly_rule_once_per_idempotency_key_without_balance_effect(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $startDate = RecurringDateRange::businessDate();
        $payload = $this->payload($account, $category, ['start_date' => $startDate]);

        $created = $this->postJson('/api/v1/recurring-transactions', $payload, ['Idempotency-Key' => 'rule-1'])
            ->assertCreated()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.frequency', 'monthly')
            ->assertJsonPath('data.currency_code', 'BRL')
            ->assertJsonPath('data.paused_reason', null)
            ->assertJsonPath('data.financial_account.id', $account->id)
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.generated_occurrence_count', 0)
            ->assertJsonPath('data.next_expected_occurrence', $startDate);

        $this->postJson('/api/v1/recurring-transactions', $payload, ['Idempotency-Key' => 'rule-1'])
            ->assertCreated()
            ->assertJsonPath('data.id', $created->json('data.id'));

        self::assertSame(1, RecurringTransaction::count());
        self::assertSame(500_000, $account->refresh()->current_balance_centavos);
        self::assertSame(0, $account->transactions()->count());
    }

    #[Test]
    public function future_start_rule_reports_its_first_eligible_date(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $start = CarbonImmutable::parse(RecurringDateRange::businessDate())->addDays(3)->toDateString();

        $this->postJson('/api/v1/recurring-transactions', $this->payload($account, $category, [
            'frequency' => RecurrenceFrequency::Weekly->value,
            'start_date' => $start,
        ]), ['Idempotency-Key' => 'weekly-1'])
            ->assertCreated()
            ->assertJsonPath('data.next_expected_occurrence', $start);
    }

    #[Test]
    public function list_supports_combined_filters_and_orders_rules_with_a_next_date_first(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $otherAccount = $this->ownedAccount($user);
        $today = RecurringDateRange::businessDate();
        $lastWeek = CarbonImmutable::parse($today)->subDays(7)->toDateString();
        $nextWeek = CarbonImmutable::parse($today)->addDays(5)->toDateString();

        // Weekly rule anchored on today's weekday: next expected date is today.
        $this->postJson('/api/v1/recurring-transactions', $this->payload($account, $category, [
            'frequency' => RecurrenceFrequency::Weekly->value,
            'start_date' => $lastWeek,
        ]), ['Idempotency-Key' => 'list-a'])->assertCreated();

        $paused = $this->rule($user, ['state' => RecurrenceState::Paused, 'category_id' => $category->id]);
        $ended = $this->rule($user, ['state' => RecurrenceState::Ended, 'ended_at' => now(), 'category_id' => $category->id]);
        $otherAccountRule = $this->rule($user, [
            'financial_account_id' => $otherAccount->id,
            'start_date' => $nextWeek,
            'eligibility_starts_on' => $nextWeek,
            'schedule_cursor' => $nextWeek,
        ]);

        $response = $this->getJson('/api/v1/recurring-transactions')->assertOk();
        self::assertSame(4, $response->json('meta.total'));
        $dates = array_column($response->json('data'), 'next_expected_occurrence');
        self::assertSame([$today, $nextWeek, null, null], $dates);
        self::assertSame(
            [$otherAccountRule->id, $paused->id, $ended->id],
            array_slice(array_column($response->json('data'), 'id'), 1, 3),
        );

        $filtered = $this->getJson('/api/v1/recurring-transactions?type=expense&financial_account_id='.$otherAccount->id.'&frequency=monthly&state=active')
            ->assertOk();
        self::assertSame(1, $filtered->json('meta.total'));
        self::assertSame($otherAccountRule->id, $filtered->json('data.0.id'));

        $this->getJson('/api/v1/recurring-transactions?state=paused')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/recurring-transactions?category_id='.$category->id)->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/recurring-transactions?state=ended')->assertOk()->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function income_rules_require_income_categories(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $incomeCategory = $this->ownedCategory($user, TransactionType::Income);

        $this->postJson('/api/v1/recurring-transactions', $this->payload($account, $incomeCategory, [
            'type' => TransactionType::Income->value,
            'description' => 'Salário',
        ]), ['Idempotency-Key' => 'income-1'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'income')
            ->assertJsonPath('data.category.classification', 'income');
    }

    #[Test]
    public function guest_cannot_list_or_create_rules(): void
    {
        $this->getJson('/api/v1/recurring-transactions')->assertUnauthorized();
        $this->postJson('/api/v1/recurring-transactions', [], ['Idempotency-Key' => 'guest'])->assertUnauthorized();
    }

    #[Test]
    public function scheduled_dates_accept_brazilian_format(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $start = CarbonImmutable::parse(RecurringDateRange::businessDate())->addDays(30);

        $this->postJson('/api/v1/recurring-transactions', $this->payload($account, $category, [
            'frequency' => RecurrenceFrequency::Yearly->value,
            'start_date' => $start->format('d/m/Y'),
        ]), ['Idempotency-Key' => 'yearly-1'])
            ->assertCreated()
            ->assertJsonPath('data.start_date', $start->toDateString())
            ->assertJsonPath('data.next_expected_occurrence', $start->toDateString());
    }
}
