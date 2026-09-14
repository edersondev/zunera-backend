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

final class IndividualOccurrenceCorrectionTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function correcting_one_occurrence_leaves_the_rule_and_siblings_unchanged(): void
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

        [$april, $may] = Transaction::query()->orderBy('transaction_date')->get()->all();

        $this->patchJson('/api/v1/transactions/'.$april->id, [
            'amount_centavos' => 11_500,
            'description' => 'Exceção de abril',
        ], ['Idempotency-Key' => 'occurrence-edit'])
            ->assertOk()
            ->assertJsonPath('data.amount_centavos', 11_500)
            ->assertJsonPath('data.recurrence_source.id', $rule->id)
            ->assertJsonPath('data.recurrence_source.scheduled_date', $start);

        self::assertSame(11_500, $april->refresh()->amount_centavos);
        self::assertSame(10_000, $may->refresh()->amount_centavos);
        self::assertSame(10_000, $rule->refresh()->amount_centavos);
        self::assertSame($rule->id, $april->recurring_transaction_id);
        self::assertSame($start, $april->recurrence_scheduled_date?->toDateString());
        self::assertSame($today, $may->recurrence_scheduled_date?->toDateString());
    }

    #[Test]
    public function removing_and_restoring_one_occurrence_keeps_its_source_link(): void
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
        app(RecurringOccurrenceService::class)->processRule($rule->id, $today);
        $occurrence = Transaction::query()->sole();

        $this->postJson('/api/v1/transactions/'.$occurrence->id.'/remove', [], ['Idempotency-Key' => 'remove-one'])->assertOk();
        self::assertSame($rule->id, $occurrence->refresh()->recurring_transaction_id);
        self::assertSame($today, $occurrence->recurrence_scheduled_date?->toDateString());
        self::assertSame(500_000, $account->refresh()->current_balance_centavos);

        $this->postJson('/api/v1/transactions/'.$occurrence->id.'/restore', ['status' => 'pending'], ['Idempotency-Key' => 'restore-one'])
            ->assertOk()
            ->assertJsonPath('data.recurrence_source.id', $rule->id);
        self::assertSame($today, $occurrence->refresh()->recurrence_scheduled_date?->toDateString());
        self::assertSame(500_000, $account->refresh()->current_balance_centavos);
    }

    #[Test]
    public function generating_returns_no_later_occurrence_for_a_removed_date(): void
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
        ]);
        $service = app(RecurringOccurrenceService::class);
        $service->processRule($rule->id, $today);
        $removed = Transaction::query()->orderBy('transaction_date')->first();
        $this->postJson('/api/v1/transactions/'.$removed->id.'/remove', [], ['Idempotency-Key' => 'remove-first'])->assertOk();

        self::assertSame(0, $service->processRule($rule->id, $today));
        self::assertSame(2, Transaction::query()->count());
        self::assertSame(1, Transaction::query()->whereNotNull('removed_at')->count());
    }
}
