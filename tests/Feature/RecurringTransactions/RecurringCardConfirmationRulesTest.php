<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\CreditCards\CreditCardStatus;
use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Models\RecurringCardOccurrence;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class RecurringCardConfirmationRulesTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function future_actual_date_cannot_mutate_an_expected_occurrence(): void
    {
        [$rule, $occurrence] = $this->expectedOccurrence();

        $this->postJson($this->confirmUrl($rule->id, $occurrence->id), [
            'actual_purchase_date' => '2099-01-01',
        ], ['Idempotency-Key' => 'future-date'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['actual_purchase_date']);

        self::assertSame(CardOccurrenceState::Expected, $occurrence->fresh()->state);
        self::assertNull($occurrence->fresh()->actual_purchase_date);
        self::assertNull($occurrence->fresh()->purchase);
    }

    #[Test]
    public function foreign_card_and_category_cannot_replace_an_expected_occurrence(): void
    {
        [$rule, $occurrence] = $this->expectedOccurrence();
        $foreign = User::factory()->create();
        $foreignCard = $this->ownedCard($foreign);
        $foreignCategory = $this->ownedCategory($foreign);

        $this->postJson($this->confirmUrl($rule->id, $occurrence->id), [
            'credit_card_id' => $foreignCard->id,
        ], ['Idempotency-Key' => 'foreign-card'])->assertNotFound();
        $this->postJson($this->confirmUrl($rule->id, $occurrence->id), [
            'category_id' => $foreignCategory->id,
        ], ['Idempotency-Key' => 'foreign-category'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);

        self::assertNull($occurrence->fresh()->action_claim_key);
        self::assertNull($occurrence->fresh()->purchase);
    }

    #[Test]
    public function eligible_one_date_replacement_records_after_original_card_becomes_unavailable(): void
    {
        [$rule, $occurrence] = $this->expectedOccurrence();
        $originalCard = $rule->creditCard;
        $replacement = $this->ownedCard($rule->user);
        $replacementCategory = $this->ownedCategory($rule->user);
        $originalCard->update(['status' => CreditCardStatus::Archived, 'archived_at' => now()]);

        $this->postJson($this->confirmUrl($rule->id, $occurrence->id), [
            'credit_card_id' => $replacement->id,
            'category_id' => $replacementCategory->id,
            'actual_amount_centavos' => 16_500,
            'actual_purchase_date' => '2026-09-13',
        ], ['Idempotency-Key' => 'replacement'])->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Recorded->value);

        $purchase = $occurrence->fresh()->purchase;
        self::assertNotNull($purchase);
        self::assertSame($replacement->id, $purchase->credit_card_id);
        self::assertSame($replacementCategory->id, $purchase->category_id);
        self::assertSame($originalCard->id, $occurrence->fresh()->credit_card_id_original);
        self::assertSame($originalCard->id, $rule->fresh()->credit_card_id);
    }

    #[Test]
    public function stale_over_limit_confirmation_keeps_choices_without_a_purchase(): void
    {
        [$rule, $occurrence] = $this->expectedOccurrence(1_000);
        $url = $this->confirmUrl($rule->id, $occurrence->id);

        $this->postJson($url, [
            'actual_amount_centavos' => 16_500,
            'actual_purchase_date' => '2026-09-13',
        ], ['Idempotency-Key' => 'over-limit-first'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'OVER_LIMIT_CONFIRMATION_REQUIRED');

        $this->postJson('/api/v1/credit-cards/'.$rule->credit_card_id.'/purchases', [
            'category_id' => $rule->category_id,
            'description' => 'Intervening charge',
            'purchase_date' => '2026-09-14',
            'total_amount_centavos' => 500,
            'installment_count' => 1,
        ], ['Idempotency-Key' => 'intervening-charge'])->assertCreated();

        $this->postJson($url, [
            'confirm_over_limit' => true,
            'expected_available_credit_centavos' => 1_000,
        ], ['Idempotency-Key' => 'over-limit-stale'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_over_limit_confirmation');

        self::assertSame(CardOccurrenceState::Expected, $occurrence->fresh()->state);
        self::assertSame(16_500, $occurrence->fresh()->actual_amount_centavos);
        self::assertSame('2026-09-13', $occurrence->fresh()->actual_purchase_date->toDateString());
        self::assertNull($occurrence->fresh()->action_claim_key);
        self::assertNull($occurrence->fresh()->purchase);
    }

    /** @return array{0: RecurringTransaction, 1: RecurringCardOccurrence} */
    private function expectedOccurrence(int $cardLimit = 1_000_000): array
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user, $cardLimit);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'generation_mode' => CardGenerationMode::Confirmation,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24');

        return [$rule, RecurringCardOccurrence::query()->firstOrFail()];
    }

    private function confirmUrl(int $ruleId, int $occurrenceId): string
    {
        return '/api/v1/recurring-transactions/'.$ruleId.'/occurrences/'.$occurrenceId.'/confirm';
    }
}
