<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\NotificationEvent;
use App\Models\NotificationProjectionFact;
use App\Models\User;
use App\Services\Notifications\StatementNotificationProjector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class StatementNotificationTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function first_eligibility_inside_three_day_window_emits_once_then_advances_stages(): void
    {
        [$user, $statement] = $this->statement();
        $projector = app(StatementNotificationProjector::class);
        $this->businessDay('2026-09-15');
        $projector->evaluate((int) $user->id, (int) $statement->id);
        $projector->evaluate((int) $user->id, (int) $statement->id);
        self::assertSame(1, NotificationEvent::query()->where('type', 'statement_approaching')->count());

        $this->businessDay('2026-09-17');
        $projector->evaluate((int) $user->id, (int) $statement->id);
        self::assertSame(1, NotificationEvent::query()->where('type', 'statement_due_today')->count());
        self::assertNotNull(NotificationEvent::query()->where('type', 'statement_approaching')->firstOrFail()->resolved_at);

        $this->businessDay('2026-09-18');
        $projector->evaluate((int) $user->id, (int) $statement->id);
        self::assertSame(1, NotificationEvent::query()->where('type', 'statement_overdue')->count());
        self::assertNotNull(NotificationEvent::query()->where('type', 'statement_due_today')->firstOrFail()->resolved_at);
        self::assertNull(NotificationEvent::query()->where('type', 'statement_overdue')->firstOrFail()->resolved_at);
    }

    #[Test]
    public function first_eligibility_on_due_date_skips_approaching_and_payment_resolves(): void
    {
        [$user, $statement] = $this->statement();
        $this->businessDay('2026-09-17');
        $projector = app(StatementNotificationProjector::class);
        $projector->evaluate((int) $user->id, (int) $statement->id);
        self::assertSame(0, NotificationEvent::query()->where('type', 'statement_approaching')->count());
        self::assertSame(1, NotificationEvent::query()->where('type', 'statement_due_today')->count());

        $statement->paid_centavos = 10000;
        $statement->save();
        $projector->evaluate((int) $user->id, (int) $statement->id);
        self::assertNotNull(NotificationEvent::query()->where('type', 'statement_due_today')->firstOrFail()->resolved_at);
    }

    #[Test]
    public function partial_payment_keeps_current_amount_and_reversal_reactivates_as_read(): void
    {
        [$user, $statement] = $this->statement();
        $this->businessDay('2026-09-18');
        $projector = app(StatementNotificationProjector::class);
        $projector->evaluate((int) $user->id, (int) $statement->id);
        $event = NotificationEvent::query()->where('type', 'statement_overdue')->firstOrFail();
        self::assertStringContainsString('•••• 1234', $event->event_snapshot['summary']['en']);

        $statement->paid_centavos = 4000;
        $statement->save();
        $projector->evaluate((int) $user->id, (int) $statement->id);
        self::assertSame(6000, $event->refresh()->current_context['outstanding_centavos']);
        $statement->paid_centavos = 10000;
        $statement->save();
        $projector->evaluate((int) $user->id, (int) $statement->id);
        self::assertNotNull($event->refresh()->resolved_at);
        self::assertNotNull($event->read_at);

        $statement->paid_centavos = 0;
        $statement->save();
        $projector->evaluate((int) $user->id, (int) $statement->id);
        self::assertNull($event->refresh()->resolved_at);
        self::assertNotNull($event->read_at);
        self::assertSame(1, NotificationEvent::query()->where('type', 'statement_overdue')->count());
    }

    #[Test]
    public function open_or_zero_outstanding_statement_never_creates_unpaid_item(): void
    {
        [$user, $statement] = $this->statement();
        $this->businessDay('2026-09-08');
        app(StatementNotificationProjector::class)->evaluate((int) $user->id, (int) $statement->id);
        self::assertSame(0, NotificationEvent::query()->count());

        $this->businessDay('2026-09-15');
        $statement->paid_centavos = 10000;
        $statement->save();
        app(StatementNotificationProjector::class)->evaluate((int) $user->id, (int) $statement->id);
        self::assertSame(0, NotificationEvent::query()->count());
    }

    #[Test]
    public function accepted_payment_writes_fact_and_summary_resolves_active_reminder(): void
    {
        $user = $this->cardSignIn('2026-09-15');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 10000, 1, '2026-09-05');
        $statement = $purchase->installments()->first()->statement;
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 20000, 'current_balance_centavos' => 20000]);
        app(StatementNotificationProjector::class)->evaluate((int) $user->id, (int) $statement->id);
        self::assertSame(1, NotificationEvent::query()->whereNull('resolved_at')->count());

        $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 10000,
            'payment_date' => '2026-09-15',
        ], ['Idempotency-Key' => 'notification-payment'])->assertCreated();
        self::assertSame(1, NotificationProjectionFact::query()->where('source_kind', 'credit_card_statement')->count());
        $this->getJson('/api/v1/notifications/summary')->assertJsonPath('data.requires_action_count', 0);
        self::assertNotNull(NotificationEvent::query()->firstOrFail()->resolved_at);
    }

    #[Test]
    public function first_eligibility_on_each_of_three_prior_days_gets_one_approaching_item(): void
    {
        $projector = app(StatementNotificationProjector::class);
        foreach (['2026-09-14', '2026-09-15', '2026-09-16'] as $date) {
            [$user, $statement] = $this->statement();
            $this->businessDay($date);
            $projector->evaluate((int) $user->id, (int) $statement->id);
        }

        self::assertSame(3, NotificationEvent::query()->where('type', 'statement_approaching')->count());
    }

    #[Test]
    public function full_credit_adjustment_prevents_or_resolves_unpaid_attention(): void
    {
        [$user, $statement] = $this->statement();
        $this->businessDay('2026-09-15');
        $projector = app(StatementNotificationProjector::class);
        $projector->evaluate((int) $user->id, (int) $statement->id);

        $statement->credit_adjustment_centavos = 10000;
        $statement->save();
        $projector->evaluate((int) $user->id, (int) $statement->id);
        self::assertNotNull(NotificationEvent::query()->firstOrFail()->resolved_at);
        self::assertSame(1, NotificationEvent::query()->count());
    }

    #[Test]
    public function date_scan_records_business_midnight_as_a_utc_instant(): void
    {
        [$user, $statement] = $this->statement();
        $this->businessDay('2026-09-17');
        Cache::forget('notifications.statement_current_scan_cursor.2026-09-17');

        app(StatementNotificationProjector::class)->scanDateCandidates();

        $event = NotificationEvent::query()->where('user_id', $user->id)
            ->where('source_id', $statement->id)->where('type', 'statement_due_today')->firstOrFail();
        self::assertSame('2026-09-17 03:00:00', $event->getRawOriginal('event_at'));
        self::assertSame('2026-09-17T03:00:00+00:00', $event->event_at->toIso8601String());
    }

    #[Test]
    public function date_scan_prioritizes_new_due_statements_over_old_overdue_backlog(): void
    {
        foreach (range(1, 2) as $_) {
            [, $oldStatement] = $this->statement();
            $oldStatement->closing_date = '2026-08-03';
            $oldStatement->due_date = '2026-08-10';
            $oldStatement->save();
        }
        [$user, $dueStatement] = $this->statement();
        $this->businessDay('2026-09-17');
        Cache::forget('notifications.statement_current_scan_cursor.2026-09-17');
        Cache::forget('notifications.statement_overdue_scan_cursor');

        $projector = app(StatementNotificationProjector::class);
        self::assertSame(2, $projector->scanDateCandidates(2));
        self::assertSame(1, NotificationEvent::query()->where('user_id', $user->id)
            ->where('source_id', $dueStatement->id)->where('type', 'statement_due_today')->count());
        self::assertSame(1, NotificationEvent::query()->where('type', 'statement_overdue')->count());

        self::assertSame(1, $projector->scanDateCandidates(2));
        self::assertSame(2, NotificationEvent::query()->where('type', 'statement_overdue')->count());
    }

    #[Test]
    public function presentation_timezone_change_does_not_duplicate_business_stage(): void
    {
        [$user, $statement] = $this->statement();
        $this->travelTo(CarbonImmutable::parse('2026-09-17 04:00:00', 'UTC'));
        $projector = app(StatementNotificationProjector::class);
        $projector->evaluate((int) $user->id, (int) $statement->id);
        config(['app.timezone' => 'Asia/Tokyo']);
        $projector->evaluate((int) $user->id, (int) $statement->id);

        self::assertSame(1, NotificationEvent::query()->where('type', 'statement_due_today')->count());
    }

    /** @return array{User, CreditCardStatement} */
    private function statement(): array
    {
        $user = User::factory()->create();
        $card = CreditCard::factory()->withUser($user)->create(['last_four' => '1234']);
        $statement = CreditCardStatement::factory()->forCard($card)->closed()
            ->closing('2026-09-10', '2026-09-17')
            ->create(['original_amount_centavos' => 10000]);

        return [$user, $statement];
    }

    private function businessDay(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 12:00:00', 'America/Sao_Paulo'));
    }
}
