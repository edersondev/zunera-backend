<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Models\RecurringCardOccurrence;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class RecurringCardOccurrenceActionsTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_approves_an_over_limit_occurrence_once(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user, 1_000);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);
        app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');

        $occurrence = RecurringCardOccurrence::query()->where('recurring_transaction_id', $rule->id)->firstOrFail();
        self::assertSame(CardOccurrenceState::AwaitingOverLimit, $occurrence->state);

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm', [
            'confirm_over_limit' => true,
        ], ['Idempotency-Key' => 'approve-1'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Recorded->value)
            ->assertJsonPath('data.purchase_id', fn ($id) => $id !== null);

        // Replaying the approval does not create a second purchase.
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm', [
            'confirm_over_limit' => true,
        ], ['Idempotency-Key' => 'approve-2'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Recorded->value);

        self::assertSame(1, $card->purchases()->count());
    }

    #[Test]
    public function owner_dismisses_an_expected_or_over_limit_occurrence(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user, 1_000);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);
        app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');

        $occurrence = RecurringCardOccurrence::query()->firstOrFail();
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/dismiss', [], ['Idempotency-Key' => 'dismiss-1'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Dismissed->value);

        self::assertSame(0, $card->purchases()->count());
    }

    #[Test]
    public function confirmation_records_chosen_values_without_changing_the_rule_template(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $otherCard = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $otherCategory = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'generation_mode' => CardGenerationMode::Confirmation,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);
        app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');

        $occurrence = RecurringCardOccurrence::query()->where('recurring_transaction_id', $rule->id)->firstOrFail();
        self::assertSame(CardOccurrenceState::Expected, $occurrence->state);

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm', [
            'actual_amount_centavos' => 16_500,
            'actual_purchase_date' => '2026-09-13',
            'credit_card_id' => $otherCard->id,
            'category_id' => $otherCategory->id,
        ], ['Idempotency-Key' => 'confirm-1'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Recorded->value)
            ->assertJsonPath('data.actual_amount_centavos', 16_500)
            ->assertJsonPath('data.actual_purchase_date', '2026-09-13');

        $purchase = $occurrence->refresh()->purchase;
        self::assertNotNull($purchase);
        self::assertSame(16_500, $purchase->total_amount_centavos);
        self::assertSame($otherCard->id, $purchase->credit_card_id);
        self::assertSame($otherCategory->id, $purchase->category_id);

        // Rule template unchanged.
        self::assertSame(15_000, $rule->fresh()->amount_centavos);
        self::assertSame($card->id, $rule->fresh()->credit_card_id);
    }

    #[Test]
    public function confirmation_rejects_a_future_actual_date(): void
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
        app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');

        $occurrence = RecurringCardOccurrence::query()->firstOrFail();
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm', [
            'actual_purchase_date' => '2099-01-01',
        ], ['Idempotency-Key' => 'confirm-future'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['actual_purchase_date']);

        self::assertSame(0, $card->purchases()->count());
        self::assertSame(CardOccurrenceState::Expected, $occurrence->fresh()->state);
    }

    #[Test]
    public function occurrence_list_exposes_card_occurrences_owner_scoped(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);
        app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');

        $this->getJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences')
            ->assertOk()
            ->assertJsonPath('data.0.state', CardOccurrenceState::Recorded->value)
            ->assertJsonPath('data.0.scheduled_date', '2026-09-01');
    }
}
