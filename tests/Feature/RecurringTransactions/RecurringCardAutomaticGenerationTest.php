<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Models\CreditCardPurchase;
use App\Models\RecurringCardOccurrence;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\OverlappingCardAttempts;

final class RecurringCardAutomaticGenerationTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function one_rule_date_survives_one_hundred_overlapping_generation_attempts(): void
    {
        OverlappingCardAttempts::inIsolatedDatabase(function (string $database): void {
            $user = User::factory()->create();
            $card = $this->ownedCard($user);
            $category = $this->ownedCategory($user);
            $rule = $this->cardRule($user, $card, [
                'category_id' => $category->id,
                'start_date' => '2026-09-24',
                'eligibility_starts_on' => '2026-09-24',
                'schedule_cursor' => '2026-09-24',
            ]);

            $result = OverlappingCardAttempts::race($database, 'generate', $user->id, $rule->id);

            self::assertSame(100, $result['successes'] + $result['failures']);
            self::assertGreaterThan(0, $result['successes']);
            self::assertSame(1, $rule->cardOccurrences()->count());
            self::assertSame(1, $card->purchases()->count());
            self::assertSame('2026-09-24', $rule->fresh()->schedule_cursor->toDateString());
        });
    }

    #[Test]
    public function paused_rule_resumes_all_due_dates_through_its_inclusive_end_date(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'frequency' => RecurrenceFrequency::Weekly,
            'state' => RecurrenceState::Paused,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
            'end_date' => '2026-09-15',
        ]);

        self::assertSame(0, app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24'));
        self::assertSame(0, $rule->cardOccurrences()->count());

        $rule->update(['state' => RecurrenceState::Active]);
        $result = app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');
        self::assertSame(3, $result['created']);
        self::assertSame(['2026-09-01', '2026-09-08', '2026-09-15'],
            $rule->cardOccurrences()->orderBy('scheduled_date')->get()->map(fn (RecurringCardOccurrence $occurrence): string => $occurrence->scheduled_date->toDateString())->all());
        self::assertSame(3, $card->purchases()->count());
        self::assertSame(RecurrenceState::Ended, $rule->fresh()->state);
    }

    #[Test]
    #[DataProvider('monthlyBoundaryCases')]
    public function automatic_generation_preserves_the_scheduled_month_end_date(
        string $anchor,
        string $cursor,
        string $expectedDate,
    ): void {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'start_date' => $anchor,
            'eligibility_starts_on' => $anchor,
            'schedule_cursor' => $cursor,
        ]);

        self::assertSame(1, app(RecurringOccurrenceService::class)->processRule($rule->id, $expectedDate));
        $occurrence = $rule->cardOccurrences()->firstOrFail();
        self::assertSame($expectedDate, $occurrence->scheduled_date->toDateString());
        self::assertSame($expectedDate, $occurrence->purchase?->purchase_date->toDateString());
        self::assertSame(1, $occurrence->purchase?->installments()->count());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function monthlyBoundaryCases(): iterable
    {
        foreach (['2024-01-29', '2024-01-30', '2024-01-31', '2024-02-29'] as $anchor) {
            $anchorDay = (int) (new DateTimeImmutable($anchor))->format('d');
            for ($offset = 1; $offset <= 25; $offset++) {
                $month = (new DateTimeImmutable($anchor))->modify('first day of this month')->modify("+$offset months");
                $day = min($anchorDay, (int) $month->format('t'));
                $expectedDate = $month->format('Y-m-').sprintf('%02d', $day);
                yield "$anchor / $offset" => [$anchor, $month->format('Y-m-d'), $expectedDate];
            }
        }
    }

    #[Test]
    public function automatic_rule_records_one_source_linked_purchase_per_due_date(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);

        $service = app(RecurringOccurrenceService::class);
        $result = $service->processDueRules('2026-09-24');

        self::assertSame(1, $result['created']);
        $occurrence = RecurringCardOccurrence::query()->where('recurring_transaction_id', $rule->id)->firstOrFail();
        self::assertSame(CardOccurrenceState::Recorded, $occurrence->state);
        self::assertSame('2026-09-01', $occurrence->scheduled_date->toDateString());

        $purchase = $occurrence->purchase;
        self::assertNotNull($purchase);
        self::assertSame(1, $purchase->installments()->count());
        self::assertSame(15_000, $purchase->total_amount_centavos);
        self::assertSame(0, Transaction::query()->where('recurring_transaction_id', $rule->id)->count());

        // Idempotent re-run does not duplicate.
        $this->assertSame(0, $service->processDueRules('2026-09-24')['created']);
        self::assertSame(1, RecurringCardOccurrence::query()->where('recurring_transaction_id', $rule->id)->count());
        self::assertSame(1, $card->purchases()->count());
    }

    #[Test]
    public function over_limit_automatic_generation_awaits_owner_approval_without_purchase(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user, 1_000);
        $category = $this->ownedCategory($user);
        $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);

        $service = app(RecurringOccurrenceService::class);
        $service->processDueRules('2026-09-24');

        $occurrence = RecurringCardOccurrence::query()->firstOrFail();
        self::assertSame(CardOccurrenceState::AwaitingOverLimit, $occurrence->state);
        self::assertNull($occurrence->purchase);
        self::assertSame(0, $card->purchases()->count());
    }

    #[Test]
    public function confirmation_mode_leaves_a_snapshot_only_expected_occurrence(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'generation_mode' => CardGenerationMode::Confirmation,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);

        $service = app(RecurringOccurrenceService::class);
        $service->processDueRules('2026-09-24');

        $occurrence = RecurringCardOccurrence::query()->where('recurring_transaction_id', $rule->id)->firstOrFail();
        self::assertSame(CardOccurrenceState::Expected, $occurrence->state);
        self::assertNull($occurrence->purchase);
        self::assertSame(0, $card->purchases()->count());
        self::assertSame(CardGenerationMode::Confirmation, $occurrence->generation_mode_snapshot);
    }

    #[Test]
    public function catch_up_commits_each_date_independently_and_resumes_after_failure(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'frequency' => RecurrenceFrequency::Weekly,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);

        $service = app(RecurringOccurrenceService::class);
        $result = $service->processDueRules('2026-09-22');

        self::assertSame(4, $result['created']);
        self::assertSame(4, RecurringCardOccurrence::query()->where('recurring_transaction_id', $rule->id)->count());
        self::assertSame(4, $card->purchases()->count());
    }

    #[Test]
    public function a_failed_middle_date_does_not_rollback_earlier_or_skip_later_dates(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'frequency' => RecurrenceFrequency::Weekly,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);

        $attempts = 0;
        CreditCardPurchase::creating(function () use (&$attempts): void {
            $attempts++;
            if ($attempts === 2) {
                throw new RuntimeException('Injected purchase failure.');
            }
        });

        $result = app(RecurringOccurrenceService::class)->processDueRules('2026-09-15');

        self::assertSame(3, $result['created']);
        self::assertSame(2, $card->purchases()->count());
        self::assertSame(
            [CardOccurrenceState::Recorded, CardOccurrenceState::Failed, CardOccurrenceState::Recorded],
            RecurringCardOccurrence::query()
                ->where('recurring_transaction_id', $rule->id)
                ->orderBy('scheduled_date')
                ->pluck('state')
                ->all(),
        );
        self::assertSame('2026-09-15', $rule->fresh()->schedule_cursor->toDateString());
    }

    #[Test]
    public function an_unrepresentable_date_stops_only_its_rule_without_advancing_its_cursor(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $firstRule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'frequency' => RecurrenceFrequency::Weekly,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
            'end_date' => '2026-09-15',
        ]);
        $secondRule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);

        RecurringCardOccurrence::creating(function (RecurringCardOccurrence $occurrence) use ($firstRule): void {
            if ($occurrence->recurring_transaction_id === $firstRule->id && $occurrence->scheduled_date->toDateString() === '2026-09-08') {
                throw new RuntimeException('Injected occurrence persistence failure.');
            }
        });

        $result = app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');

        self::assertSame(2, $result['created']);
        self::assertSame('2026-09-01', $firstRule->fresh()->schedule_cursor->toDateString());
        self::assertTrue($firstRule->fresh()->isActive());
        self::assertSame(['2026-09-01'], $firstRule->cardOccurrences()->orderBy('scheduled_date')->pluck('scheduled_date')->map->toDateString()->all());
        self::assertSame(1, $secondRule->cardOccurrences()->count());
    }
}
