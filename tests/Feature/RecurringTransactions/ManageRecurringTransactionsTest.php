<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Models\Transaction;
use App\Services\RecurringTransactions\RecurringDateRange;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class ManageRecurringTransactionsTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function edit_applies_to_future_dates_and_preserves_generated_snapshots(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();
        $start = CarbonImmutable::parse($today)->subDays(7)->toDateString();
        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'frequency' => RecurrenceFrequency::Weekly,
            'start_date' => $start,
            'eligibility_starts_on' => $start,
            'schedule_cursor' => $start,
            'amount_centavos' => 10_000,
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, $today);
        $generated = Transaction::query()->orderBy('transaction_date')->get();
        self::assertCount(2, $generated);

        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [
            'amount_centavos' => 33_000,
            'description' => 'Plano família atualizado',
        ], ['Idempotency-Key' => 'edit-1'])
            ->assertOk()
            ->assertJsonPath('data.amount_centavos', 33_000)
            ->assertJsonPath('data.description', 'Plano família atualizado');

        self::assertSame(10_000, $generated->first()->refresh()->amount_centavos);
        self::assertSame(10_000, $generated->last()->refresh()->amount_centavos);

        $startDate = CarbonImmutable::parse($today)->addDays(21)->toDateString();
        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [
            'start_date' => $startDate,
            'end_date' => CarbonImmutable::parse($today)->addDays(60)->toDateString(),
        ], ['Idempotency-Key' => 'edit-2'])
            ->assertOk()
            ->assertJsonPath('data.start_date', $startDate);

        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [
            'end_date' => null,
        ], ['Idempotency-Key' => 'edit-3'])
            ->assertOk()
            ->assertJsonPath('data.end_date', null);

        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [
            'start_date' => '2030-06-10',
            'end_date' => '2030-06-01',
        ], ['Idempotency-Key' => 'edit-4'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
    }

    #[Test]
    public function editing_start_date_reanchors_at_the_current_business_date_without_backfill(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();
        $futureStart = CarbonImmutable::parse($today)->addDays(14)->toDateString();
        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'frequency' => RecurrenceFrequency::Weekly,
            'start_date' => $futureStart,
            'eligibility_starts_on' => $futureStart,
            'schedule_cursor' => $futureStart,
        ]);

        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [
            'start_date' => CarbonImmutable::parse($today)->subDays(21)->toDateString(),
        ], ['Idempotency-Key' => 'reanchor-start-date'])
            ->assertOk()
            ->assertJsonPath('data.start_date', CarbonImmutable::parse($today)->subDays(21)->toDateString())
            ->assertJsonPath('data.next_expected_occurrence', $today);

        $rule->refresh();
        self::assertSame($today, $rule->eligibility_starts_on->toDateString());
        self::assertSame($today, $rule->schedule_cursor->toDateString());
        self::assertSame(1, app(RecurringOccurrenceService::class)->processRule($rule->id, $today));
        self::assertSame($today, Transaction::query()->sole()->transaction_date->toDateString());
    }

    #[Test]
    public function shortening_an_active_rule_to_a_past_end_date_ends_it_immediately(): void
    {
        $user = $this->signInUser();
        $today = RecurringDateRange::businessDate();
        $rule = $this->rule($user, [
            'start_date' => CarbonImmutable::parse($today)->subDays(14)->toDateString(),
            'eligibility_starts_on' => CarbonImmutable::parse($today)->subDays(14)->toDateString(),
            'schedule_cursor' => $today,
        ]);

        $pastEndDate = CarbonImmutable::parse($today)->subDay()->toDateString();
        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [
            'end_date' => $pastEndDate,
        ], ['Idempotency-Key' => 'end-by-past-date'])
            ->assertOk()
            ->assertJsonPath('data.end_date', $pastEndDate)
            ->assertJsonPath('data.state', 'ended')
            ->assertJsonPath('data.next_expected_occurrence', null);

        self::assertNotNull($rule->refresh()->ended_at);
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/pause', [], ['Idempotency-Key' => 'pause-ended-by-date'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'recurrence_ended');
    }

    #[Test]
    public function lifecycle_conflicts_return_documented_codes(): void
    {
        $user = $this->signInUser();
        $rule = $this->rule($user);

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/resume', [], ['Idempotency-Key' => 'resume-active'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'recurrence_not_paused');

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/pause', [], ['Idempotency-Key' => 'pause-1'])->assertOk();
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/pause', [], ['Idempotency-Key' => 'pause-2'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'recurrence_not_active');
        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, ['amount_centavos' => 5_000], ['Idempotency-Key' => 'edit-paused'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'recurrence_not_active');

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/end', [], ['Idempotency-Key' => 'end-1'])
            ->assertOk()
            ->assertJsonPath('data.state', 'ended');
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/resume', [], ['Idempotency-Key' => 'resume-ended'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'recurrence_ended');
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/pause', [], ['Idempotency-Key' => 'pause-ended'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'recurrence_ended');
        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, ['amount_centavos' => 7_000], ['Idempotency-Key' => 'edit-ended'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'recurrence_ended');

        $this->getJson('/api/v1/recurring-transactions/'.$rule->id)->assertOk()->assertJsonPath('data.state', 'ended');
    }

    #[Test]
    public function changed_idempotency_key_reuse_conflicts_while_exact_replay_succeeds(): void
    {
        $user = $this->signInUser();
        $rule = $this->rule($user);

        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, ['amount_centavos' => 12_000], ['Idempotency-Key' => 'key-1'])->assertOk();
        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, ['amount_centavos' => 12_000], ['Idempotency-Key' => 'key-1'])->assertOk();
        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, ['amount_centavos' => 13_000], ['Idempotency-Key' => 'key-1'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_key_reused');

        self::assertSame(12_000, $rule->refresh()->amount_centavos);
    }

    #[Test]
    public function ended_rules_keep_their_history_and_report_no_next_occurrence(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();
        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'start_date' => $today,
            'eligibility_starts_on' => $today,
            'schedule_cursor' => $today,
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, $today);
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/end', [], ['Idempotency-Key' => 'end-history'])->assertOk();

        $detail = $this->getJson('/api/v1/recurring-transactions/'.$rule->id)->assertOk();
        self::assertSame('ended', $detail->json('data.state'));
        self::assertNull($detail->json('data.next_expected_occurrence'));
        self::assertSame(1, $detail->json('data.generated_occurrence_count'));
        self::assertSame(1, Transaction::query()->count());
    }
}
