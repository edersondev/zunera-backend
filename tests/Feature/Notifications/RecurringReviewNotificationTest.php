<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionStatus;
use App\Models\NotificationEvent;
use App\Models\NotificationProjectionFact;
use App\Models\RecurringCardOccurrence;
use App\Models\Transaction;
use App\Services\Notifications\NotificationFactDispatcher;
use App\Services\Notifications\NotificationReconciler;
use App\Services\Notifications\RecurringReviewProjector;
use App\Services\RecurringTransactions\RecurringCardOccurrenceActionService;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\RecurringTransactions\RecurringTransactionFeatureTestCase;

final class RecurringReviewNotificationTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function generated_ordinary_pending_occurrence_has_one_exact_review_and_resolves_when_effective(): void
    {
        $user = $this->signInUser();
        $rule = $this->rule($user, $this->dateRule());
        self::assertSame(1, app(RecurringOccurrenceService::class)->processRule((int) $rule->id, '2026-09-24'));
        $transaction = Transaction::query()->where('recurring_transaction_id', $rule->id)->firstOrFail();
        self::assertSame(1, NotificationProjectionFact::query()->where('source_kind', 'transaction')->count());
        $this->drain();
        $event = NotificationEvent::query()->where('source_kind', 'transaction')->firstOrFail();
        self::assertSame((int) $transaction->id, (int) $event->source_id);
        self::assertNull($event->resolved_at);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.destination.params.transaction_id', $transaction->id);

        $rule->update(['state' => RecurrenceState::Paused]);
        app(RecurringReviewProjector::class)->evaluateTransaction((int) $user->id, (int) $transaction->id);
        self::assertNull($event->refresh()->resolved_at);

        $this->patchJson('/api/v1/transactions/'.$transaction->id, ['status' => 'effective'], ['Idempotency-Key' => 'effective-review'])->assertOk();
        $this->drain();
        self::assertNotNull($event->refresh()->resolved_at);
        self::assertNotNull($event->read_at);
        self::assertSame(1, NotificationEvent::query()->where('source_kind', 'transaction')->count());
    }

    #[Test]
    public function expected_then_failed_card_occurrence_updates_one_review_without_claiming_charge(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $rule = $this->cardRule($user, $card, [...$this->dateRule(), 'generation_mode' => CardGenerationMode::Confirmation]);
        self::assertSame(1, app(RecurringOccurrenceService::class)->processRule((int) $rule->id, '2026-09-24'));
        $occurrence = RecurringCardOccurrence::query()->firstOrFail();
        $this->drain();
        $event = NotificationEvent::query()->where('source_kind', 'recurring_card_occurrence')->firstOrFail();
        self::assertSame('expected', $event->current_context['state']);
        self::assertSame((int) $occurrence->id, (int) $event->source_id);

        DB::transaction(function () use ($occurrence): void {
            $occurrence->state = CardOccurrenceState::Failed;
            $occurrence->failure_code = 'internal-only-code';
            $occurrence->save();
            app(RecurringReviewProjector::class)->captureCard($occurrence);
        });
        $this->drain();
        self::assertSame(1, NotificationEvent::query()->where('source_kind', 'recurring_card_occurrence')->count());
        self::assertSame('failed', $event->refresh()->current_context['state']);
        self::assertStringContainsString('retry', $event->current_context['summary']['en']);
        self::assertStringNotContainsString('internal-only-code', json_encode($event->current_context));
        self::assertStringNotContainsString('charged', json_encode($event->current_context));

        app(RecurringCardOccurrenceActionService::class)->dismiss($user, $rule, $occurrence);
        $this->drain();
        self::assertNotNull($event->refresh()->resolved_at);
        self::assertSame(1, NotificationEvent::query()->where('source_kind', 'recurring_card_occurrence')->count());
    }

    #[Test]
    public function automatic_success_creates_no_review_and_over_limit_requires_one(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $rule = $this->cardRule($user, $card, $this->dateRule());
        app(RecurringOccurrenceService::class)->processRule((int) $rule->id, '2026-09-24');
        $this->drain();
        self::assertSame(CardOccurrenceState::Recorded, $rule->cardOccurrences()->firstOrFail()->state);
        self::assertSame(0, NotificationEvent::query()->where('type', 'recurrence_review')->count());

        $limited = $this->ownedCard($user, 1000);
        $limitedRule = $this->cardRule($user, $limited, [...$this->dateRule(), 'amount_centavos' => 15000]);
        app(RecurringOccurrenceService::class)->processRule((int) $limitedRule->id, '2026-09-24');
        $this->drain();
        $event = NotificationEvent::query()->where('type', 'recurrence_review')->firstOrFail();
        self::assertSame('awaiting_over_limit', $event->current_context['state']);
        self::assertSame((int) $limitedRule->cardOccurrences()->firstOrFail()->id, (int) $event->source_id);
    }

    #[Test]
    public function removed_generated_transaction_resolves_review_and_restored_pending_reuses_identity(): void
    {
        $user = $this->signInUser();
        $rule = $this->rule($user, $this->dateRule());
        app(RecurringOccurrenceService::class)->processRule((int) $rule->id, '2026-09-24');
        $transaction = Transaction::query()->where('recurring_transaction_id', $rule->id)->firstOrFail();
        $this->drain();
        $event = NotificationEvent::query()->firstOrFail();
        $this->postJson('/api/v1/transactions/'.$transaction->id.'/remove', [], ['Idempotency-Key' => 'remove-review'])->assertOk();
        $this->drain();
        self::assertNotNull($event->refresh()->resolved_at);
        $this->postJson('/api/v1/transactions/'.$transaction->id.'/restore', ['status' => TransactionStatus::Pending->value], ['Idempotency-Key' => 'restore-review'])->assertOk();
        $this->drain();
        self::assertNull($event->refresh()->resolved_at);
        self::assertNotNull($event->read_at);
        self::assertSame(1, NotificationEvent::query()->count());
    }

    /** @return array<string, string> */
    private function dateRule(): array
    {
        return ['start_date' => '2026-09-24', 'eligibility_starts_on' => '2026-09-24', 'schedule_cursor' => '2026-09-24'];
    }

    private function drain(): void
    {
        $result = app(NotificationReconciler::class)->drain(app(NotificationFactDispatcher::class)->handle(...));
        self::assertSame(0, $result['failed']);
    }
}
