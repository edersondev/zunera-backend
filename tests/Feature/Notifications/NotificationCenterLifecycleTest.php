<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Data\Notifications\NotificationProjectionData;
use App\Models\FinancialGoal;
use App\Models\NotificationEvent;
use App\Models\NotificationProjectionFact;
use App\Models\User;
use App\Services\Notifications\NotificationProjectionFactService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotificationCenterLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function equal_timestamp_cursor_is_stable_and_bound_to_view(): void
    {
        $user = User::factory()->create();
        $stamp = now()->subHour();
        $ids = [];
        foreach (range(1, 5) as $number) {
            $ids[] = (int) $this->event($user, $number, $stamp)->id;
        }
        $this->signIn($user);
        $first = $this->getJson('/api/v1/notifications?limit=2')->assertOk()->json();
        self::assertSame([$ids[4], $ids[3]], array_map(static fn ($item) => (int) $item['id'], $first['data']));
        $cursor = urlencode($first['next_cursor']);
        $this->getJson('/api/v1/notifications?limit=2&cursor='.$cursor)
            ->assertOk()->assertJsonPath('data.0.id', $ids[2])->assertJsonPath('data.1.id', $ids[1]);
        $this->getJson('/api/v1/notifications?view=unread&limit=2&cursor='.$cursor)->assertUnprocessable();
    }

    #[Test]
    public function unread_count_is_exact_at_ninety_nine_and_one_hundred(): void
    {
        $user = User::factory()->create();
        for ($number = 1; $number <= 100; $number++) {
            $this->event($user, $number, now());
        }
        $this->signIn($user);
        $this->getJson('/api/v1/notifications/summary')->assertJsonPath('data.unread_count', 100);
        NotificationEvent::query()->where('event_key', 'fixture-100')->update(['read_at' => now()]);
        $this->getJson('/api/v1/notifications/summary')->assertJsonPath('data.unread_count', 99);
    }

    #[Test]
    public function retention_uses_later_of_creation_or_resolution_and_never_drops_active_action(): void
    {
        $user = User::factory()->create();
        $old = $this->event($user, 1, now()->subDays(100));
        $old->forceFill(['resolved_at' => now()->subDays(99)])->save();
        $recentlyResolved = $this->event($user, 2, now()->subDays(100));
        $recentlyResolved->forceFill(['resolved_at' => now()->subDays(10)])->save();
        $active = $this->event($user, 3, now()->subDays(100));
        $this->signIn($user);

        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(2, 'data');
        self::assertNull($active->refresh()->resolved_at);
        self::assertSame('visible', $recentlyResolved->refresh()->visibility);
    }

    #[Test]
    public function summary_drains_owners_pending_milestone_fact_before_counting(): void
    {
        $user = User::factory()->create();
        $goal = FinancialGoal::query()->create(['user_id' => $user->id, 'name' => 'Trip', 'target_centavos' => 10000, 'status' => 'active']);
        DB::transaction(fn () => app(NotificationProjectionFactService::class)->capture(new NotificationProjectionData(
            userId: (int) $user->id,
            sourceKind: 'goal',
            sourceId: (int) $goal->id,
            qualifiedType: 'goal_reached',
            qualifiedAt: CarbonImmutable::now(),
            context: ['target_centavos' => 10000, 'goal_name' => 'Trip'],
        )));
        $this->signIn($user);

        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.type', 'goal_reached');
        self::assertSame(1, NotificationEvent::query()->count());
        self::assertNotNull(NotificationProjectionFact::query()->firstOrFail()->processed_at);
    }

    private function event(User $user, int $number, CarbonInterface $created): NotificationEvent
    {
        $event = NotificationEvent::query()->create([
            'user_id' => $user->id,
            'event_key' => 'fixture-'.$number,
            'type' => 'statement_due_today',
            'category' => 'credit_cards',
            'severity' => 'attention',
            'source_kind' => 'credit_card_statement',
            'source_id' => 999999,
            'event_at' => $created,
            'visibility' => 'visible',
            'event_snapshot' => ['title' => ['pt-BR' => 'Fatura', 'en' => 'Statement'], 'summary' => ['pt-BR' => 'Vence.', 'en' => 'Due.']],
        ]);
        $event->forceFill(['created_at' => $created])->save();

        return $event;
    }

    private function signIn(User $user): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }
}
