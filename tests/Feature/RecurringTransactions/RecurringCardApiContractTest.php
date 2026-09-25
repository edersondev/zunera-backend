<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Models\RecurringCardOccurrence;
use App\Models\User;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class RecurringCardApiContractTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function occurrence_actions_reject_foreign_or_missing_resources_privacy_safely(): void
    {
        $owner = User::factory()->create();
        $card = $this->ownedCard($owner);
        $rule = $this->cardRule($owner, $card, ['generation_mode' => CardGenerationMode::Confirmation]);
        $occurrence = RecurringCardOccurrence::query()->create([
            'user_id' => $owner->id,
            'recurring_transaction_id' => $rule->id,
            'scheduled_date' => '2026-09-24',
            'generation_mode_snapshot' => CardGenerationMode::Confirmation,
            'scheduled_amount_centavos' => 15_000,
            'description_snapshot' => 'Academia',
            'notes_snapshot' => null,
            'category_id_original' => $rule->category_id,
            'credit_card_id_original' => $card->id,
            'card_identity_snapshot' => ['name' => $card->name, 'institution_name' => $card->institution_name, 'last_four' => $card->last_four, 'status' => 'active'],
            'category_name_snapshot' => 'Academia',
            'state' => CardOccurrenceState::Expected,
        ]);

        $this->signInUser();

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm', [], ['Idempotency-Key' => 'foreign-confirm'])
            ->assertNotFound();
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/dismiss', [], ['Idempotency-Key' => 'foreign-dismiss'])
            ->assertNotFound();
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/retry', [], ['Idempotency-Key' => 'foreign-retry'])
            ->assertNotFound();
    }

    #[Test]
    public function occurrence_actions_require_an_idempotency_key(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $rule = $this->cardRule($user, $card, [
            'generation_mode' => CardGenerationMode::Confirmation,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);
        app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');

        $occurrence = RecurringCardOccurrence::query()->firstOrFail();

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    #[Test]
    public function recorded_and_dismissed_occurrences_return_documented_conflict_codes(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $rule = $this->cardRule($user, $card, [
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);
        app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');

        $occurrence = RecurringCardOccurrence::query()->firstOrFail();
        self::assertSame(CardOccurrenceState::Recorded, $occurrence->state);

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/dismiss', [], ['Idempotency-Key' => 'dismiss-recorded'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'occurrence_already_recorded');
    }

    #[Test]
    public function an_active_claim_returns_the_retryable_occurrence_action_in_progress_code(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $rule = $this->cardRule($user, $card, [
            'generation_mode' => CardGenerationMode::Confirmation,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);
        app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');

        $occurrence = RecurringCardOccurrence::query()->firstOrFail();
        $occurrence->update(['action_claim_key' => 'active-claim', 'action_claimed_at' => now()]);

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm', [], ['Idempotency-Key' => 'competing'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'occurrence_action_in_progress');
    }

    #[Test]
    public function pagination_and_owner_isolation_hold_on_the_occurrence_list(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $rule = $this->cardRule($user, $card, [
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);
        app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');

        $this->getJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences?page=1&per_page=1')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta', 'links'])
            ->assertJsonCount(1, 'data');
    }
}
