<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Models\RecurringCardOccurrence;
use App\Models\RecurringTransaction;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class RecurringCardConfirmationContractTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function due_confirmation_item_lists_without_financial_effect_and_confirms_with_replay(): void
    {
        [$rule, $occurrence] = $this->expectedOccurrence();
        $base = '/api/v1/recurring-transactions/'.$rule->id.'/occurrences';

        $this->getJson($base)->assertOk()
            ->assertJsonStructure(['data' => [['id', 'scheduled_date', 'state', 'generation_mode', 'scheduled_amount_centavos', 'purchase_id']], 'meta', 'links'])
            ->assertJsonPath('data.0.state', CardOccurrenceState::Expected->value)
            ->assertJsonPath('data.0.purchase_id', null);
        self::assertNull($occurrence->purchase);

        $payload = ['actual_amount_centavos' => 16_500, 'actual_purchase_date' => '2026-09-13'];
        $url = $base.'/'.$occurrence->id.'/confirm';
        $first = $this->postJson($url, $payload, ['Idempotency-Key' => 'contract-confirm'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Recorded->value)
            ->assertJsonPath('data.actual_amount_centavos', 16_500)
            ->assertJsonPath('data.actual_purchase_date', '2026-09-13');
        $this->postJson($url, $payload, ['Idempotency-Key' => 'contract-confirm'])
            ->assertOk()
            ->assertExactJson($first->json());
        self::assertSame(1, $occurrence->fresh()->purchase()->count());
    }

    #[Test]
    public function dismissal_replays_and_blocks_later_confirmation(): void
    {
        [$rule, $occurrence] = $this->expectedOccurrence();
        $base = '/api/v1/recurring-transactions/'.$rule->id.'/occurrences/'.$occurrence->id;

        $first = $this->postJson($base.'/dismiss', [], ['Idempotency-Key' => 'contract-dismiss'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Dismissed->value);
        $this->postJson($base.'/dismiss', [], ['Idempotency-Key' => 'contract-dismiss'])
            ->assertOk()
            ->assertExactJson($first->json());
        $this->postJson($base.'/confirm', [], ['Idempotency-Key' => 'contract-after-dismiss'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'occurrence_dismissed');
        self::assertNull($occurrence->fresh()->purchase);
    }

    #[Test]
    public function failed_confirmation_readback_and_omitted_field_retry_keep_owner_choices(): void
    {
        [$rule, $occurrence] = $this->expectedOccurrence();
        $occurrence->update([
            'state' => CardOccurrenceState::Failed,
            'actual_amount_centavos' => 16_500,
            'actual_purchase_date' => '2026-09-13',
            'failure_code' => 'purchase_recording_failed',
        ]);
        $base = '/api/v1/recurring-transactions/'.$rule->id.'/occurrences';

        $this->getJson($base)->assertOk()
            ->assertJsonPath('data.0.state', CardOccurrenceState::Failed->value)
            ->assertJsonPath('data.0.actual_amount_centavos', 16_500)
            ->assertJsonPath('data.0.actual_purchase_date', '2026-09-13')
            ->assertJsonPath('data.0.failure_code', 'purchase_recording_failed');

        $this->postJson($base.'/'.$occurrence->id.'/confirm', [], ['Idempotency-Key' => 'contract-retry'])
            ->assertOk()
            ->assertJsonPath('data.state', CardOccurrenceState::Recorded->value)
            ->assertJsonPath('data.actual_amount_centavos', 16_500)
            ->assertJsonPath('data.actual_purchase_date', '2026-09-13');
        self::assertSame(16_500, $occurrence->fresh()->purchase->total_amount_centavos);
    }

    /** @return array{0: RecurringTransaction, 1: RecurringCardOccurrence} */
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

        return [$rule, RecurringCardOccurrence::query()->firstOrFail()];
    }
}
