<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Models\RecurringCardOccurrence;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class RecurringCardLifecycleTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('futureOnlyEditCases')]
    public function owner_edit_preserves_the_missed_dates_original_amount(int $oldAmount, int $newAmount): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'amount_centavos' => $oldAmount,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);

        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [
            'amount_centavos' => $newAmount,
        ], ['Idempotency-Key' => 'edit-'.$oldAmount])->assertOk();

        $occurrence = $rule->cardOccurrences()->firstOrFail();
        self::assertSame($oldAmount, $occurrence->scheduled_amount_centavos);
        self::assertSame($oldAmount, $occurrence->purchase?->total_amount_centavos);
        self::assertSame($newAmount, $rule->fresh()->amount_centavos);
    }

    /** @return iterable<string, array{int, int}> */
    public static function futureOnlyEditCases(): iterable
    {
        for ($case = 0; $case < 100; $case++) {
            yield "amount edit $case" => [15_000 + $case, 20_000 + $case];
        }
    }

    #[Test]
    public function destination_change_is_rejected_even_when_other_fields_are_valid(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'start_date' => '2026-10-01',
            'eligibility_starts_on' => '2026-10-01',
            'schedule_cursor' => '2026-10-01',
        ]);

        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [
            'destination_type' => 'financial_account',
            'amount_centavos' => 20_000,
        ], ['Idempotency-Key' => 'switch-destination'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['destination_type']);

        self::assertSame(15_000, $rule->fresh()->amount_centavos);
        self::assertTrue($rule->fresh()->isCardDestination());
    }

    #[Test]
    public function missed_date_uses_old_card_mode_and_schedule_before_the_template_changes(): void
    {
        $user = $this->signInUser();
        $oldCard = $this->ownedCard($user);
        $newCard = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $oldCard, [
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);

        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [
            'amount_centavos' => 20_000,
            'credit_card_id' => $newCard->id,
            'generation_mode' => CardGenerationMode::Confirmation->value,
            'start_date' => '2026-10-02',
        ], ['Idempotency-Key' => 'future-template-edit'])->assertOk();

        $occurrence = $rule->cardOccurrences()->firstOrFail();
        self::assertSame(15_000, $occurrence->scheduled_amount_centavos);
        self::assertSame($oldCard->id, $occurrence->credit_card_id_original);
        self::assertSame(CardGenerationMode::Automatic, $occurrence->generation_mode_snapshot);
        self::assertSame($oldCard->id, $occurrence->purchase->credit_card_id);
        self::assertSame('2026-09-01', $occurrence->scheduled_date->toDateString());
        self::assertSame(20_000, $rule->fresh()->amount_centavos);
        self::assertSame($newCard->id, $rule->fresh()->credit_card_id);
        self::assertSame(CardGenerationMode::Confirmation, $rule->fresh()->generation_mode);
        self::assertSame('2026-10-02', $rule->fresh()->start_date->toDateString());
    }

    #[Test]
    public function ending_a_rule_represents_a_missed_expected_date_and_keeps_it_actionable(): void
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

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/end', [], ['Idempotency-Key' => 'end-with-due'])
            ->assertOk()
            ->assertJsonPath('data.state', RecurrenceState::Ended->value);
        $occurrence = $rule->cardOccurrences()->firstOrFail();
        self::assertSame(CardOccurrenceState::Expected, $occurrence->state);

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/dismiss', [], [
            'Idempotency-Key' => 'dismiss-after-end',
        ])->assertOk()->assertJsonPath('data.state', CardOccurrenceState::Dismissed->value);
        self::assertNull($occurrence->fresh()->purchase);
    }

    #[Test]
    #[DataProvider('ownerMutationCases')]
    public function incomplete_catch_up_preserves_earlier_purchase_and_rejects_rule_mutation(string $action): void
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
        RecurringCardOccurrence::creating(function (RecurringCardOccurrence $occurrence) use ($rule): void {
            if ($occurrence->recurring_transaction_id === $rule->id && $occurrence->scheduled_date->toDateString() === '2026-09-08') {
                throw new RuntimeException('Injected occurrence persistence failure.');
            }
        });

        $url = '/api/v1/recurring-transactions/'.$rule->id;
        $response = $action === 'update'
            ? $this->patchJson($url, ['amount_centavos' => 20_000], ['Idempotency-Key' => 'incomplete-update'])
            : $this->postJson($url.'/'.$action, [], ['Idempotency-Key' => 'incomplete-'.$action]);
        $response->assertStatus(409)->assertJsonPath('code', 'recurrence_due_processing_incomplete');

        self::assertSame(1, $rule->cardOccurrences()->count());
        self::assertSame(1, $card->purchases()->count());
        self::assertSame('2026-09-01', $rule->cardOccurrences()->firstOrFail()->scheduled_date->toDateString());
        self::assertSame(15_000, $rule->fresh()->amount_centavos);
        self::assertSame(RecurrenceState::Active, $rule->fresh()->state);
    }

    /** @return iterable<string, array{string}> */
    public static function ownerMutationCases(): iterable
    {
        yield 'update' => ['update'];
        yield 'pause' => ['pause'];
        yield 'end' => ['end'];
    }

    #[Test]
    public function editing_an_amount_after_a_missed_due_date_represents_the_old_value_first(): void
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

        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [
            'amount_centavos' => 20_000,
        ], ['Idempotency-Key' => 'lifecycle-edit'])
            ->assertOk()
            ->assertJsonPath('data.amount_centavos', 20_000);

        $occurrence = RecurringCardOccurrence::query()->where('recurring_transaction_id', $rule->id)->firstOrFail();
        self::assertSame(15_000, $occurrence->scheduled_amount_centavos);
        self::assertSame(CardOccurrenceState::Recorded, $occurrence->state);
        self::assertSame(15_000, $occurrence->purchase->total_amount_centavos);
    }

    #[Test]
    public function pausing_a_card_rule_represents_due_dates_first_and_keeps_them_actionable(): void
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

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/pause', [], ['Idempotency-Key' => 'lifecycle-pause'])
            ->assertOk()
            ->assertJsonPath('data.state', RecurrenceState::Paused->value);

        $occurrence = RecurringCardOccurrence::query()->where('recurring_transaction_id', $rule->id)->firstOrFail();
        self::assertSame(CardOccurrenceState::Expected, $occurrence->state);

        // Still confirmable after pause.
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm', [
            'actual_amount_centavos' => 16_500,
        ], ['Idempotency-Key' => 'confirm-after-pause'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Recorded->value);
    }

    #[Test]
    public function archiving_a_card_auto_pauses_its_active_rules(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, ['category_id' => $category->id]);

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/archive', [], ['Idempotency-Key' => 'archive-card'])
            ->assertOk();

        self::assertSame(RecurrenceState::Paused, $rule->fresh()->state);
        self::assertSame('association_archived', $rule->fresh()->paused_reason->value);
    }

    #[Test]
    public function represented_occurrences_keep_their_snapshot_after_a_rule_edit(): void
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
        app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');

        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [
            'amount_centavos' => 30_000,
        ], ['Idempotency-Key' => 'edit-after-record'])
            ->assertOk();

        $occurrence = RecurringCardOccurrence::query()->firstOrFail();
        self::assertSame(15_000, $occurrence->scheduled_amount_centavos);
        self::assertSame(30_000, $rule->fresh()->amount_centavos);
    }
}
