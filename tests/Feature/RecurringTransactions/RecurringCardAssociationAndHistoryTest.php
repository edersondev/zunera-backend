<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Models\RecurringCardOccurrence;
use App\Models\RecurringTransaction;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class RecurringCardAssociationAndHistoryTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function archiving_a_card_pauses_the_rule_until_an_owned_active_card_repairs_it(): void
    {
        $user = $this->signInUser();
        $originalCard = $this->ownedCard($user);
        $replacement = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $originalCard, [
            'category_id' => $category->id,
            'start_date' => '2026-10-01',
            'eligibility_starts_on' => '2026-10-01',
            'schedule_cursor' => '2026-10-01',
        ]);

        $this->postJson('/api/v1/credit-cards/'.$originalCard->id.'/archive', [], ['Idempotency-Key' => 'archive-rule-card'])->assertOk();
        self::assertSame(RecurrenceState::Paused, $rule->fresh()->state);

        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [
            'credit_card_id' => $replacement->id,
        ], ['Idempotency-Key' => 'repair-rule-card'])->assertOk();
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/resume', [], ['Idempotency-Key' => 'resume-rule-card'])
            ->assertOk()
            ->assertJsonPath('data.state', RecurrenceState::Active->value);
        self::assertSame($replacement->id, $rule->fresh()->credit_card_id);
    }

    #[Test]
    public function archived_original_category_can_be_replaced_for_one_expected_date(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $originalCategory = $this->ownedCategory($user);
        $replacement = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $originalCategory->id,
            'generation_mode' => CardGenerationMode::Confirmation,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24');
        $occurrence = $rule->cardOccurrences()->firstOrFail();

        $this->postJson('/api/v1/categories/'.$originalCategory->id.'/archive', [], ['Idempotency-Key' => 'archive-original-category'])->assertOk();
        self::assertSame(RecurrenceState::Paused, $rule->fresh()->state);

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm', [
            'category_id' => $replacement->id,
        ], ['Idempotency-Key' => 'replace-one-category'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Recorded->value);
        self::assertSame($originalCategory->id, $occurrence->fresh()->category_id_original);
        self::assertSame($replacement->id, $occurrence->fresh()->purchase->category_id);
        self::assertSame($originalCategory->id, $rule->fresh()->category_id);
    }

    #[Test]
    public function purchase_correction_keeps_source_date_reserved(): void
    {
        [$rule, $occurrence] = $this->recordedOccurrence();
        $purchase = $occurrence->purchase;

        $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, [
            'total_amount_centavos' => 18_000,
            'purchase_date' => '2026-09-23',
        ], ['Idempotency-Key' => 'correct-recurring-purchase'])->assertOk();

        self::assertSame($occurrence->id, $purchase->fresh()->recurring_card_occurrence_id);
        self::assertSame('2026-09-24', $occurrence->fresh()->scheduled_date->toDateString());
        self::assertSame(18_000, $purchase->fresh()->total_amount_centavos);
        app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24');
        self::assertSame(1, $rule->cardOccurrences()->count());
        self::assertSame(1, $rule->cardOccurrences()->firstOrFail()->purchase()->count());
    }

    #[Test]
    public function refund_keeps_source_date_reserved_and_never_regenerates_the_purchase(): void
    {
        [$rule, $occurrence] = $this->recordedOccurrence();
        $purchase = $occurrence->purchase;

        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => 15_000,
            'event_date' => '2026-09-24',
        ], ['Idempotency-Key' => 'refund-recurring-purchase'])->assertCreated();

        self::assertSame($occurrence->id, $purchase->fresh()->recurring_card_occurrence_id);
        self::assertSame(CardOccurrenceState::Recorded, $occurrence->fresh()->state);
        app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24');
        self::assertSame(1, $rule->cardOccurrences()->count());
        self::assertSame(1, $rule->cardOccurrences()->firstOrFail()->purchase()->count());
    }

    /** @return array{0: RecurringTransaction, 1: RecurringCardOccurrence} */
    private function recordedOccurrence(): array
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-24',
            'eligibility_starts_on' => '2026-09-24',
            'schedule_cursor' => '2026-09-24',
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24');

        return [$rule, RecurringCardOccurrence::query()->firstOrFail()];
    }
}
