<?php

declare(strict_types=1);

namespace Tests\Unit\RecurringTransactions;

use App\Data\RecurringTransactions\ConfirmCardOccurrenceData;
use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Exceptions\RecurringTransactions\RecurrenceStateException;
use App\Models\RecurringCardOccurrence;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Services\CreditCards\CreditCardPurchaseService;
use App\Services\RecurringTransactions\RecurringCardOccurrenceActionService;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\RecurringTransactions\RecurringTransactionFeatureTestCase;

final class RecurringCardConfirmationRetryTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function stale_claim_cannot_record_or_overwrite_a_newer_confirmation_choice(): void
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
        app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24');

        $occurrence = RecurringCardOccurrence::query()->firstOrFail();
        $occurrence->update([
            'action_claim_key' => 'first-claim',
            'action_claimed_at' => now(),
            'action_choice_version' => 1,
            'actual_amount_centavos' => 16_500,
            'actual_purchase_date' => '2026-09-13',
        ]);
        $staleClaim = $occurrence->fresh();
        $occurrence->update([
            'action_claim_key' => 'second-claim',
            'action_choice_version' => 2,
            'actual_amount_centavos' => 20_000,
            'actual_purchase_date' => '2026-09-14',
        ]);

        try {
            app(CreditCardPurchaseService::class)->recordOccurrencePurchase(
                $user,
                $staleClaim,
                $card->id,
                $category->id,
                16_500,
                '2026-09-13',
                $staleClaim->description_snapshot,
                $staleClaim->notes_snapshot,
                false,
                null,
                $staleClaim->action_claim_key,
                $staleClaim->action_choice_version,
            );
            self::fail('Stale confirmation claim must not record a purchase.');
        } catch (RecurrenceStateException $exception) {
            self::assertSame('occurrence_action_in_progress', $exception->errorCode());
        }

        self::assertNull($occurrence->fresh()->purchase);
        self::assertSame('second-claim', $occurrence->fresh()->action_claim_key);
        self::assertSame(20_000, $occurrence->fresh()->actual_amount_centavos);
        self::assertSame('2026-09-14', $occurrence->fresh()->actual_purchase_date->toDateString());
    }

    #[Test]
    public function active_claim_blocks_a_distinct_confirmation_and_dismissal(): void
    {
        [$user, $rule, $occurrence] = $this->expectedOccurrence();
        $occurrence->update([
            'action_claim_key' => 'active-claim',
            'action_claimed_at' => now(),
            'action_choice_version' => 1,
            'actual_amount_centavos' => 16_500,
        ]);

        $actions = app(RecurringCardOccurrenceActionService::class);
        try {
            $actions->confirm($user, $rule, $occurrence, new ConfirmCardOccurrenceData(actualAmountCentavos: 20_000));
            self::fail('A competing confirmation must be rejected.');
        } catch (RecurrenceStateException $exception) {
            self::assertSame('occurrence_action_in_progress', $exception->errorCode());
        }
        try {
            $actions->dismiss($user, $rule, $occurrence);
            self::fail('Dismissal must wait for the active claim.');
        } catch (RecurrenceStateException $exception) {
            self::assertSame('occurrence_action_in_progress', $exception->errorCode());
        }

        self::assertSame(16_500, $occurrence->fresh()->actual_amount_centavos);
        self::assertSame(CardOccurrenceState::Expected, $occurrence->fresh()->state);
    }

    #[Test]
    public function stale_claim_recovers_only_when_no_purchase_exists(): void
    {
        [$user, $rule, $occurrence] = $this->expectedOccurrence();
        $occurrence->update([
            'action_claim_key' => 'interrupted-claim',
            'action_claimed_at' => now()->subMinutes(6),
            'action_choice_version' => 1,
            'actual_amount_centavos' => 16_500,
            'actual_purchase_date' => '2026-09-13',
        ]);

        $actions = app(RecurringCardOccurrenceActionService::class);
        $result = $actions->confirm($user, $rule, $occurrence, new ConfirmCardOccurrenceData);
        self::assertSame(CardOccurrenceState::Recorded, $result['occurrence']->state);
        self::assertSame(16_500, $occurrence->fresh()->purchase->total_amount_centavos);
        self::assertSame(2, $occurrence->fresh()->action_choice_version);

        $occurrence->refresh()->update([
            'state' => CardOccurrenceState::Expected,
            'action_claim_key' => 'stale-after-commit',
            'action_claimed_at' => now()->subMinutes(6),
        ]);
        try {
            $actions->confirm($user, $rule, $occurrence, new ConfirmCardOccurrenceData);
            self::fail('Recovery must check the committed source purchase.');
        } catch (RecurrenceStateException $exception) {
            self::assertSame('occurrence_already_recorded', $exception->errorCode());
        }
        self::assertSame(1, $occurrence->fresh()->purchase()->count());
    }

    #[Test]
    public function explicit_retry_revision_is_revalidated_and_then_recorded(): void
    {
        [$user, $rule, $occurrence] = $this->expectedOccurrence();
        $occurrence->update([
            'state' => CardOccurrenceState::Failed,
            'actual_amount_centavos' => 16_500,
            'actual_purchase_date' => '2026-09-13',
            'action_choice_version' => 1,
        ]);
        $url = '/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id.'/confirm';

        $this->postJson($url, ['actual_purchase_date' => '2099-01-01'], ['Idempotency-Key' => 'revision-invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['actual_purchase_date']);
        self::assertSame('2026-09-13', $occurrence->fresh()->actual_purchase_date->toDateString());
        self::assertSame(1, $occurrence->fresh()->action_choice_version);

        $this->postJson($url, ['actual_amount_centavos' => 18_000], ['Idempotency-Key' => 'revision-valid'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Recorded->value)
            ->assertJsonPath('data.actual_amount_centavos', 18_000)
            ->assertJsonPath('data.actual_purchase_date', '2026-09-13');
        self::assertSame(18_000, $occurrence->fresh()->purchase->total_amount_centavos);
        self::assertSame(2, $occurrence->fresh()->action_choice_version);
    }

    /** @return array{0: User, 1: RecurringTransaction, 2: RecurringCardOccurrence} */
    private function expectedOccurrence(): array
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
        app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24');

        return [$user, $rule, RecurringCardOccurrence::query()->firstOrFail()];
    }
}
