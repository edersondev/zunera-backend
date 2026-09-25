<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Models\CreditCardPurchase;
use App\Models\RecurringCardOccurrence;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\OverlappingCardAttempts;

final class RecurringCardConfirmationRetryTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function one_hundred_overlapping_distinct_confirmation_choices_preserve_the_winner(): void
    {
        OverlappingCardAttempts::inIsolatedDatabase(function (string $database): void {
            [$user, $card, $category, $rule, $occurrence] = $this->expectedOccurrence();
            $otherCard = $this->ownedCard($user);
            $otherCategory = $this->ownedCategory($user);

            $result = OverlappingCardAttempts::race(
                $database, 'confirmation', $user->id, $rule->id, $occurrence->id, $otherCard->id, $otherCategory->id,
            );

            self::assertSame(100, $result['successes'] + $result['failures']);
            self::assertGreaterThan(0, $result['successes']);
            self::assertSame(CardOccurrenceState::Recorded, $occurrence->fresh()->state);
            self::assertSame(1, $occurrence->purchase()->count());
            $purchase = $occurrence->purchase;
            $winner = $purchase->total_amount_centavos - 15_000;
            self::assertGreaterThanOrEqual(0, $winner);
            self::assertLessThan(100, $winner);
            self::assertSame(sprintf('2026-09-%02d', 1 + $winner % 20), $purchase->purchase_date->toDateString());
            self::assertSame($winner % 2 === 0 ? $card->id : $otherCard->id, $purchase->credit_card_id);
            self::assertSame($winner % 2 === 0 ? $category->id : $otherCategory->id, $purchase->category_id);
            self::assertSame($purchase->total_amount_centavos, $occurrence->fresh()->actual_amount_centavos);
            self::assertSame($purchase->purchase_date->toDateString(), $occurrence->fresh()->actual_purchase_date->toDateString());
        });
    }

    private function expectedOccurrence(): array
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'generation_mode' => CardGenerationMode::Confirmation,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);
        app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');

        return [$user, $card, $category, $rule, RecurringCardOccurrence::query()->where('recurring_transaction_id', $rule->id)->firstOrFail()];
    }

    #[Test]
    public function interrupted_claim_recovers_retained_choices_without_a_duplicate_purchase(): void
    {
        [$user, $card, $category, $rule, $occurrence] = $this->expectedOccurrence();
        $occurrence->update([
            'actual_amount_centavos' => 16_500,
            'actual_purchase_date' => '2026-09-13',
            'action_claim_key' => 'interrupted-claim',
            'action_claimed_at' => now()->subMinutes(10),
            'action_choice_version' => 1,
        ]);
        $url = '/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm';

        $this->postJson($url, [], ['Idempotency-Key' => 'recovered-claim'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Recorded->value)
            ->assertJsonPath('data.actual_amount_centavos', 16_500);
        $this->postJson($url, ['actual_amount_centavos' => 18_000], ['Idempotency-Key' => 'later-choice'])
            ->assertOk()->assertJsonPath('data.actual_amount_centavos', 16_500);

        self::assertSame(1, $occurrence->purchase()->count());
        self::assertSame(16_500, $occurrence->purchase->total_amount_centavos);
        self::assertSame('2026-09-13', $occurrence->purchase->purchase_date->toDateString());
        self::assertNull($occurrence->fresh()->action_claim_key);
    }

    #[Test]
    public function omitted_retry_fields_reuse_the_retained_choices(): void
    {
        [$user, $card, $category, $rule, $occurrence] = $this->expectedOccurrence();
        $otherCard = $this->ownedCard($user);
        $otherCategory = $this->ownedCategory($user);

        $occurrence->update([
            'state' => CardOccurrenceState::Failed,
            'actual_amount_centavos' => 16_500,
            'actual_purchase_date' => '2026-09-13',
            'credit_card_id_override' => $otherCard->id,
            'category_id_override' => $otherCategory->id,
        ]);

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm', [], ['Idempotency-Key' => 'retry-omitted'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Recorded->value)
            ->assertJsonPath('data.actual_amount_centavos', 16_500)
            ->assertJsonPath('data.actual_purchase_date', '2026-09-13');

        $purchase = $occurrence->refresh()->purchase;
        self::assertSame(16_500, $purchase->total_amount_centavos);
        self::assertSame($otherCard->id, $purchase->credit_card_id);
        self::assertSame($otherCategory->id, $purchase->category_id);
    }

    #[Test]
    public function failed_purchase_keeps_validated_choices_and_retry_records_them_once(): void
    {
        [$user, $card, $category, $rule, $occurrence] = $this->expectedOccurrence();
        $otherCard = $this->ownedCard($user);
        $otherCategory = $this->ownedCategory($user);
        $attempts = 0;
        CreditCardPurchase::creating(function () use (&$attempts): void {
            $attempts++;
            if ($attempts === 1) {
                throw new RuntimeException('Injected confirmation purchase failure.');
            }
        });

        $url = '/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm';
        $this->postJson($url, [
            'actual_amount_centavos' => 16_500,
            'actual_purchase_date' => '2026-09-13',
            'credit_card_id' => $otherCard->id,
            'category_id' => $otherCategory->id,
        ], ['Idempotency-Key' => 'failed-choice-1'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Failed->value)
            ->assertJsonPath('data.actual_amount_centavos', 16_500)
            ->assertJsonPath('data.actual_purchase_date', '2026-09-13');

        self::assertNull($occurrence->fresh()->purchase);
        self::assertSame(0, $card->purchases()->count());
        self::assertSame(0, $otherCard->purchases()->count());
        self::assertNull($occurrence->fresh()->action_claim_key);
        self::assertSame($otherCard->id, $occurrence->fresh()->credit_card_id_override);
        self::assertSame($otherCategory->id, $occurrence->fresh()->category_id_override);

        $this->postJson($url, [], ['Idempotency-Key' => 'failed-choice-2'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Recorded->value)
            ->assertJsonPath('data.actual_amount_centavos', 16_500);

        $purchase = $occurrence->fresh()->purchase;
        self::assertNotNull($purchase);
        self::assertSame(16_500, $purchase->total_amount_centavos);
        self::assertSame('2026-09-13', $purchase->purchase_date->toDateString());
        self::assertSame($otherCard->id, $purchase->credit_card_id);
        self::assertSame($otherCategory->id, $purchase->category_id);
        self::assertSame(1, CreditCardPurchase::query()->where('recurring_card_occurrence_id', $occurrence->id)->count());
    }

    #[Test]
    public function an_active_confirmation_claim_blocks_a_competing_attempt(): void
    {
        [$user, $card, $category, $rule, $occurrence] = $this->expectedOccurrence();

        $occurrence->update([
            'action_claim_key' => 'active-claim',
            'action_claimed_at' => now(),
            'actual_amount_centavos' => 16_500,
        ]);

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm', [
            'actual_amount_centavos' => 99_999,
        ], ['Idempotency-Key' => 'competing-claim'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'occurrence_action_in_progress');

        self::assertSame(16_500, $occurrence->fresh()->actual_amount_centavos);
    }

    #[Test]
    public function confirmation_over_limit_without_approval_keeps_the_item_actionable(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user, 1_000);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'generation_mode' => CardGenerationMode::Confirmation,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);
        app(RecurringOccurrenceService::class)->processDueRules('2026-09-24');

        $occurrence = RecurringCardOccurrence::query()->firstOrFail();
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm', [
            'actual_amount_centavos' => 15_000,
        ], ['Idempotency-Key' => 'confirm-over-limit'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'OVER_LIMIT_CONFIRMATION_REQUIRED');

        self::assertSame(0, $card->purchases()->count());
        self::assertSame(CardOccurrenceState::Expected, $occurrence->fresh()->state);
    }

    #[Test]
    public function one_hundred_repeated_confirm_attempts_record_a_single_purchase_without_overwriting_choices(): void
    {
        [$user, $card, $category, $rule, $occurrence] = $this->expectedOccurrence();

        for ($attempt = 1; $attempt <= 100; $attempt++) {
            $response = $this->postJson(
                '/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm',
                ['actual_amount_centavos' => 10_000 + $attempt],
                ['Idempotency-Key' => 'confirm-attempt-'.$attempt],
            );

            $response->assertOk();
            if ($attempt === 1) {
                $response->assertJsonPath('data.state', CardOccurrenceState::Recorded->value);
            } else {
                $response->assertJsonPath('data.state', CardOccurrenceState::Recorded->value);
            }
        }

        self::assertSame(1, $card->purchases()->count());
        $purchase = $occurrence->fresh()->purchase;
        self::assertSame(10_001, $purchase->total_amount_centavos);
        self::assertSame(10_001, $occurrence->fresh()->actual_amount_centavos);
    }
}
