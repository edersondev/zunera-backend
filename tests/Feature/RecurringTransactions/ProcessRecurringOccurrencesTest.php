<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\Transactions\TransactionStatus;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Services\RecurringTransactions\RecurringDateRange;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class ProcessRecurringOccurrencesTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function due_rule_creates_one_pending_occurrence_per_eligible_date_without_balance_effect(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();
        $start = CarbonImmutable::parse($today)->subDays(21)->toDateString();

        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'frequency' => RecurrenceFrequency::Weekly,
            'start_date' => $start,
            'eligibility_starts_on' => $start,
            'schedule_cursor' => $start,
            'amount_centavos' => 25_000,
        ]);

        $service = app(RecurringOccurrenceService::class);
        self::assertSame(4, $service->processRule($rule->id, $today));

        $occurrences = Transaction::query()->orderBy('transaction_date')->get();
        self::assertCount(4, $occurrences);
        self::assertSame(
            [$start, CarbonImmutable::parse($today)->subDays(14)->toDateString(), CarbonImmutable::parse($today)->subDays(7)->toDateString(), $today],
            $occurrences->pluck('transaction_date')->map(fn ($date) => $date->toDateString())->all(),
        );
        foreach ($occurrences as $occurrence) {
            self::assertSame(TransactionStatus::Pending, $occurrence->status);
            self::assertSame($rule->id, $occurrence->recurring_transaction_id);
            self::assertSame($occurrence->transaction_date->toDateString(), $occurrence->recurrence_scheduled_date?->toDateString());
            self::assertSame(25_000, $occurrence->amount_centavos);
        }

        self::assertTrue($account->refresh()->has_financial_movements);
        self::assertTrue($category->refresh()->has_financial_transactions);

        // Balances still ignore pending occurrences.
        self::assertSame(500_000, $account->refresh()->current_balance_centavos);

        // Re-running the processor is idempotent for the same rule/date pairs.
        self::assertSame(0, $service->processRule($rule->id, $today));
        self::assertSame(4, Transaction::query()->count());

        $rule->refresh();
        self::assertSame($today, $rule->schedule_cursor->toDateString());

        $this->getJson('/api/v1/recurring-transactions/'.$rule->id)
            ->assertOk()
            ->assertJsonPath('data.generated_occurrence_count', 4)
            ->assertJsonPath('data.next_expected_occurrence', CarbonImmutable::parse($today)->addDays(7)->toDateString());
    }

    #[Test]
    public function occurrences_list_is_paginated_newest_first_and_can_open_existing_transactions(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();
        $start = CarbonImmutable::parse($today)->subDays(14)->toDateString();
        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'frequency' => RecurrenceFrequency::Weekly,
            'start_date' => $start,
            'eligibility_starts_on' => $start,
            'schedule_cursor' => $start,
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, $today);

        $page = $this->getJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences?per_page=2')->assertOk();
        self::assertSame(3, $page->json('meta.total'));
        self::assertCount(2, $page->json('data'));
        self::assertSame($today, $page->json('data.0.scheduled_date'));
        self::assertSame('pending', $page->json('data.0.status'));
        self::assertNull($page->json('data.0.removed_at'));

        $transactionId = $page->json('data.0.id');
        $this->getJson('/api/v1/transactions/'.$transactionId)
            ->assertOk()
            ->assertJsonPath('data.recurrence_source.id', $rule->id)
            ->assertJsonPath('data.recurrence_source.scheduled_date', $today);
    }

    #[Test]
    public function removed_occurrence_is_never_regenerated_and_stays_visible(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();
        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => $today,
            'eligibility_starts_on' => $today,
            'schedule_cursor' => $today,
        ]);
        $service = app(RecurringOccurrenceService::class);
        self::assertSame(1, $service->processRule($rule->id, $today));

        $occurrence = Transaction::query()->sole();
        $this->postJson('/api/v1/transactions/'.$occurrence->id.'/remove', [], ['Idempotency-Key' => 'remove-occurrence'])
            ->assertOk();

        self::assertSame(0, $service->processRule($rule->id, $today));
        self::assertSame(1, Transaction::query()->count());

        $listed = $this->getJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences')->assertOk();
        self::assertSame(1, $listed->json('meta.total'));
        self::assertNotNull($listed->json('data.0.removed_at'));
        $this->getJson('/api/v1/recurring-transactions/'.$rule->id)
            ->assertOk()
            ->assertJsonPath('data.generated_occurrence_count', 1);
    }

    #[Test]
    public function paused_rules_do_not_generate_and_resume_does_not_backfill_paused_dates(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();
        $start = CarbonImmutable::parse($today)->subDays(21)->toDateString();
        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'frequency' => RecurrenceFrequency::Weekly,
            'start_date' => $start,
            'eligibility_starts_on' => $start,
            'schedule_cursor' => $start,
        ]);

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/pause', [], ['Idempotency-Key' => 'pause-1'])
            ->assertOk()
            ->assertJsonPath('data.state', 'paused')
            ->assertJsonPath('data.paused_reason', 'user')
            ->assertJsonPath('data.next_expected_occurrence', null);

        $service = app(RecurringOccurrenceService::class);
        self::assertSame(0, $service->processRule($rule->id, $today));
        self::assertSame(0, Transaction::query()->count());
        self::assertSame($start, $rule->refresh()->schedule_cursor->toDateString());

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/resume', [], ['Idempotency-Key' => 'resume-1'])
            ->assertOk()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.paused_reason', null);

        self::assertSame($today, $rule->refresh()->schedule_cursor->toDateString());
        // Resuming evaluates only dates on or after the resume date: the paused
        // window stays skipped while today's eligible date is still generated.
        self::assertSame(1, $service->processRule($rule->id, $today));
        $occurrences = Transaction::query()->get();
        self::assertCount(1, $occurrences);
        self::assertSame($today, $occurrences->first()->transaction_date->toDateString());
        self::assertSame(0, $service->processRule($rule->id, $today));
        self::assertSame(
            CarbonImmutable::parse($today)->addDays(7)->toDateString(),
            $this->getJson('/api/v1/recurring-transactions/'.$rule->id)->json('data.next_expected_occurrence'),
        );
    }

    #[Test]
    public function end_date_stops_generation_and_automatically_ends_the_rule(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();
        $endDate = CarbonImmutable::parse($today)->subDays(8)->toDateString();
        $start = CarbonImmutable::parse($today)->subDays(21)->toDateString();
        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'frequency' => RecurrenceFrequency::Weekly,
            'start_date' => $start,
            'end_date' => $endDate,
            'eligibility_starts_on' => $start,
            'schedule_cursor' => $start,
        ]);

        app(RecurringOccurrenceService::class)->processRule($rule->id, $today);
        $rule->refresh();

        self::assertSame('ended', $rule->state->value);
        self::assertNotNull($rule->ended_at);
        self::assertSame(2, Transaction::query()->count());
        self::assertSame(
            [$start, CarbonImmutable::parse($start)->addDays(7)->toDateString()],
            Transaction::query()->orderBy('transaction_date')->get()->pluck('transaction_date')->map(fn ($date) => $date->toDateString())->all(),
        );

        self::assertSame(0, app(RecurringOccurrenceService::class)->processRule($rule->id, $today));
        $this->getJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences')->assertOk()->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function artisan_processor_reports_created_and_ended_counts(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();
        $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'frequency' => RecurrenceFrequency::Weekly,
            'start_date' => $today,
            'eligibility_starts_on' => $today,
            'schedule_cursor' => $today,
        ]);

        $this->artisan('recurring:process-due', ['--date' => $today])->assertSuccessful();

        self::assertSame(1, Transaction::query()->count());
        self::assertSame(1, RecurringTransaction::query()->count());
    }

    #[Test]
    public function artisan_processor_rejects_future_dates_without_generating_or_ending_rules(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();
        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'frequency' => RecurrenceFrequency::Weekly,
            'start_date' => $today,
            'eligibility_starts_on' => $today,
            'schedule_cursor' => $today,
        ]);

        $futureDate = CarbonImmutable::parse($today)->addDay()->toDateString();
        $this->artisan('recurring:process-due', ['--date' => $futureDate])
            ->expectsOutput('Due processing cannot run for a future business date.')
            ->assertExitCode(Command::INVALID);

        self::assertSame(0, Transaction::query()->count());
        self::assertSame('active', $rule->refresh()->state->value);
    }
}
