<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Models\RecurringCardOccurrence;
use App\Models\RecurringTransaction;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OverlappingCardAttempts;

final class RecurringCardAutomaticOccurrenceActionsTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function one_hundred_overlapping_automatic_decisions_cannot_duplicate_a_purchase(): void
    {
        OverlappingCardAttempts::inIsolatedDatabase(function (string $database): void {
            [$rule, $occurrence] = $this->awaitingOccurrence();
            $result = OverlappingCardAttempts::race($database, 'automatic', $rule->user_id, $rule->id, $occurrence->id);

            self::assertSame(100, $result['successes'] + $result['failures']);
            self::assertGreaterThan(0, $result['successes']);
            self::assertContains($occurrence->fresh()->state, [CardOccurrenceState::Recorded, CardOccurrenceState::Dismissed]);
            self::assertLessThanOrEqual(1, $occurrence->purchase()->count());
            self::assertSame(1, $rule->cardOccurrences()->count());
            if ($occurrence->fresh()->state === CardOccurrenceState::Recorded) {
                self::assertSame(1, $occurrence->purchase()->count());
            }
        });
    }

    #[Test]
    public function another_owner_cannot_read_or_act_on_an_automatic_occurrence(): void
    {
        [$rule, $occurrence] = $this->awaitingOccurrence();
        $this->deleteJson('/api/v1/auth/session')->assertNoContent();
        $this->signInUser();

        $base = '/api/v1/recurring-transactions/'.$rule->id.'/occurrences';
        $this->getJson($base)->assertNotFound();
        $this->postJson($base.'/'.$occurrence->id.'/confirm', [
            'confirm_over_limit' => true,
        ], ['Idempotency-Key' => 'foreign-approve'])->assertNotFound();
        $this->postJson($base.'/'.$occurrence->id.'/dismiss', [], ['Idempotency-Key' => 'foreign-dismiss'])->assertNotFound();

        self::assertSame(CardOccurrenceState::AwaitingOverLimit, $occurrence->fresh()->state);
        self::assertNull($occurrence->fresh()->purchase);
    }

    #[Test]
    public function over_limit_approval_requires_an_explicit_fresh_decision_and_current_credit(): void
    {
        [$rule, $occurrence] = $this->awaitingOccurrence();
        $url = '/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm';

        $this->postJson($url, [], ['Idempotency-Key' => 'missing-approval'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'OVER_LIMIT_CONFIRMATION_REQUIRED');
        $this->postJson($url, [
            'confirm_over_limit' => true,
            'expected_available_credit_centavos' => 2_000,
        ], ['Idempotency-Key' => 'stale-approval'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_over_limit_confirmation');
        self::assertNull($occurrence->fresh()->purchase);

        $first = $this->postJson($url, [
            'confirm_over_limit' => true,
            'expected_available_credit_centavos' => 1_000,
        ], ['Idempotency-Key' => 'fresh-approval'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Recorded->value);
        $this->postJson($url, [
            'confirm_over_limit' => true,
            'expected_available_credit_centavos' => 1_000,
        ], ['Idempotency-Key' => 'fresh-approval'])
            ->assertOk()
            ->assertExactJson($first->json());

        self::assertSame(1, $occurrence->fresh()->purchase()->count());
    }

    #[Test]
    public function missing_action_idempotency_key_is_rejected(): void
    {
        [$rule, $occurrence] = $this->awaitingOccurrence();
        $url = '/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id;

        $this->postJson($url.'/confirm', ['confirm_over_limit' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);
        $this->postJson($url.'/dismiss')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);
        self::assertSame(CardOccurrenceState::AwaitingOverLimit, $occurrence->fresh()->state);
    }

    #[Test]
    public function invalid_action_keys_are_rejected_and_occurrence_remains_readable(): void
    {
        [$rule, $occurrence] = $this->awaitingOccurrence();
        $base = '/api/v1/recurring-transactions/'.$rule->id.'/occurrences';

        $this->getJson($base)->assertOk()->assertJsonPath('data.0.id', $occurrence->id);
        $this->postJson($base.'/'.$occurrence->id.'/confirm', ['confirm_over_limit' => true], ['Idempotency-Key' => str_repeat('a', 256)])
            ->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);
        $this->postJson($base.'/'.$occurrence->id.'/dismiss', [], ['Idempotency-Key' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);
        self::assertNull($occurrence->fresh()->purchase);
    }

    #[Test]
    public function dismissal_reserves_the_date_permanently(): void
    {
        [$rule, $occurrence] = $this->awaitingOccurrence();
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/dismiss', [], [
            'Idempotency-Key' => 'dismiss-automatic',
        ])->assertOk()->assertJsonPath('data.state', CardOccurrenceState::Dismissed->value);

        app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');
        self::assertSame(1, $rule->cardOccurrences()->count());
        self::assertNull($occurrence->fresh()->purchase);
    }

    /** @return array{0: RecurringTransaction, 1: RecurringCardOccurrence} */
    private function awaitingOccurrence(): array
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

        return [$rule, RecurringCardOccurrence::query()->firstOrFail()];
    }
}
