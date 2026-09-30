<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Data\Notifications\NotificationCandidate;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotificationIdentityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function duplicate_qualification_uses_one_key_independent_of_wording_or_identity_order(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationEventService::class);
        $first = $this->candidate((int) $user->id, ['year' => 2026, 'month' => 9, 'stage' => 'exceeded']);
        $second = $this->candidate((int) $user->id, ['stage' => 'exceeded', 'month' => 9, 'year' => 2026], ['label' => 'renamed']);

        self::assertSame($service->buildKey($first), $service->buildKey($second));
        self::assertSame($service->qualify($first, true)->id, $service->qualify($second, true)->id);
        self::assertSame(1, NotificationEvent::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function skipped_stage_and_suppressed_identity_never_backfill(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationEventService::class);
        $approaching = $this->candidate((int) $user->id, ['year' => 2026, 'month' => 9, 'stage' => 'approaching']);

        $service->consume($approaching);
        self::assertSame('suppressed', $service->qualify($approaching, true)->visibility);
        self::assertSame(1, NotificationEvent::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function retained_recross_stays_read_and_expired_identity_stays_expired(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationEventService::class);
        $candidate = $this->candidate((int) $user->id, ['year' => 2026, 'month' => 9, 'stage' => 'exceeded']);
        $event = $service->qualify($candidate, true);
        $event->read_at = now();
        $event->save();
        $service->resolve($candidate);
        $reopened = $service->qualify($candidate, true);
        self::assertNull($reopened->resolved_at);
        self::assertNotNull($reopened->read_at);

        $reopened->forceFill(['visibility' => 'expired', 'expired_at' => now()])->save();
        self::assertSame('expired', $service->qualify($candidate, true)->visibility);
        self::assertSame(1, NotificationEvent::query()->where('user_id', $user->id)->count());
    }

    /** @param array<string, int|string> $identity
     * @param  array<string, mixed>  $snapshot
     */
    private function candidate(int $userId, array $identity, array $snapshot = ['label' => 'Groceries']): NotificationCandidate
    {
        return new NotificationCandidate(
            userId: $userId,
            type: 'budget_exceeded',
            category: 'budgets',
            severity: 'critical',
            sourceKind: 'budget_plan',
            sourceId: 42,
            identity: $identity,
            eventAt: CarbonImmutable::parse('2026-09-29 12:00:00', 'UTC'),
            snapshot: $snapshot,
        );
    }
}
