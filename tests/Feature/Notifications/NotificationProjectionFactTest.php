<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Data\Notifications\NotificationCandidate;
use App\Data\Notifications\NotificationProjectionData;
use App\Models\NotificationEvent;
use App\Models\NotificationProjectionFact;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use App\Services\Notifications\NotificationProjectionFactService;
use App\Services\Notifications\NotificationReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotificationProjectionFactTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function fact_is_durable_only_with_accepted_source_transaction(): void
    {
        $user = User::factory()->create();
        $data = new NotificationProjectionData((int) $user->id, 'goal', 7, qualifiedType: 'goal_reached', qualifiedAt: CarbonImmutable::now());
        $service = app(NotificationProjectionFactService::class);

        try {
            DB::transaction(function () use ($service, $data): void {
                $service->capture($data);
                throw new \RuntimeException('Source mutation rejected');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(0, NotificationProjectionFact::query()->count());

        DB::transaction(fn () => $service->capture($data));
        self::assertSame(1, NotificationProjectionFact::query()->count());
    }

    #[Test]
    public function duplicate_facts_drain_to_one_event_and_stale_source_creates_none(): void
    {
        $user = User::factory()->create();
        $data = new NotificationProjectionData((int) $user->id, 'goal', 7, qualifiedType: 'goal_reached', qualifiedAt: CarbonImmutable::now());
        DB::transaction(function () use ($data): void {
            app(NotificationProjectionFactService::class)->capture($data);
            app(NotificationProjectionFactService::class)->capture($data);
        });

        $candidate = new NotificationCandidate((int) $user->id, 'goal_reached', 'financial_goals', 'success', 'goal', 7, ['target_centavos' => 10000], CarbonImmutable::now(), actionable: false);
        $result = app(NotificationReconciler::class)->drain(function (NotificationProjectionFact $fact) use ($candidate): void {
            app(NotificationEventService::class)->qualify($candidate, true);
        });
        self::assertSame(2, $result['processed']);
        self::assertSame(1, NotificationEvent::query()->count());
        self::assertSame(0, app(NotificationReconciler::class)->drain(static fn () => null)['processed']);

        DB::transaction(fn () => app(NotificationProjectionFactService::class)->capture(new NotificationProjectionData((int) $user->id, 'goal', 99999)));
        app(NotificationReconciler::class)->drain(static fn (NotificationProjectionFact $fact) => self::assertSame(99999, $fact->source_id));
        self::assertSame(1, NotificationEvent::query()->count());
    }

    #[Test]
    public function failure_retries_without_leaking_exception_text_or_marking_processed(): void
    {
        $user = User::factory()->create();
        DB::transaction(fn () => app(NotificationProjectionFactService::class)->capture(new NotificationProjectionData((int) $user->id, 'goal', 8)));
        $reconciler = app(NotificationReconciler::class);
        $result = $reconciler->drain(static function (): void {
            throw new \RuntimeException('Sensitive goal title');
        });
        self::assertSame(1, $result['failed']);
        $fact = NotificationProjectionFact::query()->firstOrFail();
        self::assertNull($fact->processed_at);
        self::assertSame(1, $fact->attempts);
        self::assertSame('RuntimeException', $fact->last_error_code);
        $fact->available_at = now()->subSecond();
        $fact->save();

        self::assertSame(1, $reconciler->drain(static fn () => null)['processed']);
        self::assertNotNull($fact->refresh()->processed_at);
    }
}
