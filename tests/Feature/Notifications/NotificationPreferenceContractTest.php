<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Data\Notifications\NotificationCandidate;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use App\Services\Notifications\NotificationPreferenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotificationPreferenceContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function protected_routes_return_four_true_defaults_and_owner_scoped_updates(): void
    {
        $this->getJson('/api/v1/notification-preferences')->assertUnauthorized();
        $this->patchJson('/api/v1/notification-preferences/budgets', ['enabled' => false])->assertUnauthorized();
        $user = $this->signIn();
        $this->getJson('/api/v1/notification-preferences')->assertOk()->assertExactJson(['data' => [
            'credit_cards' => true, 'recurring_transactions' => true, 'budgets' => true, 'financial_goals' => true,
        ]]);
        $this->patchJson('/api/v1/notification-preferences/budgets', ['enabled' => false])->assertOk()->assertJsonPath('data.budgets', false);
        $this->patchJson('/api/v1/notification-preferences/credit_cards', ['enabled' => false])->assertOk()->assertJsonPath('data.credit_cards', false);
        $this->getJson('/api/v1/notification-preferences')->assertJsonPath('data.budgets', false);

        $other = User::factory()->create();
        $this->actingAs($other);
        $this->getJson('/api/v1/notification-preferences')->assertJsonPath('data.budgets', true);
        self::assertFalse(app(NotificationPreferenceService::class)->all((int) $user->id)['budgets']);
    }

    #[Test]
    public function invalid_category_body_and_boolean_are_rejected(): void
    {
        $this->signIn();
        $this->patchJson('/api/v1/notification-preferences/unknown', ['enabled' => false])->assertNotFound();
        $this->patchJson('/api/v1/notification-preferences/budgets', [])->assertUnprocessable()->assertJsonValidationErrors('enabled');
        $this->patchJson('/api/v1/notification-preferences/budgets', ['enabled' => 'false'])->assertUnprocessable()->assertJsonValidationErrors('enabled');
        $this->patchJson('/api/v1/notification-preferences/budgets', ['enabled' => false, 'extra' => 1])->assertUnprocessable()->assertJsonValidationErrors('extra');
        $this->patchJson('/api/v1/notification-preferences/budgets', ['enabled' => false, 'extra' => null])->assertUnprocessable()->assertJsonValidationErrors('extra');
    }

    #[Test]
    public function delayed_evaluation_uses_first_qualification_time_and_reenable_never_backfills(): void
    {
        $user = $this->signIn();
        $preferences = app(NotificationPreferenceService::class);
        $events = app(NotificationEventService::class);
        $base = CarbonImmutable::parse('2026-09-29 12:00:00', 'UTC');
        $this->travelTo($base->addMinute());
        foreach (NotificationPreferenceService::CATEGORIES as $category) {
            $preferences->set((int) $user->id, $category, false);
        }

        foreach (NotificationPreferenceService::CATEGORIES as $index => $category) {
            $early = $this->candidate((int) $user->id, $category, $index + 1, $base);
            $events->qualify($early, $preferences->enabledAt((int) $user->id, $category, $base));
            self::assertSame('visible', NotificationEvent::query()->where('event_key', $events->buildKey($early))->firstOrFail()->visibility);

            $lateAt = $base->addMinutes(2);
            $late = $this->candidate((int) $user->id, $category, $index + 101, $lateAt);
            $events->qualify($late, $preferences->enabledAt((int) $user->id, $category, $lateAt));
            self::assertSame('suppressed', NotificationEvent::query()->where('event_key', $events->buildKey($late))->firstOrFail()->visibility);
        }

        $this->travelTo($base->addMinutes(3));
        foreach (NotificationPreferenceService::CATEGORIES as $category) {
            $preferences->set((int) $user->id, $category, true);
        }
        foreach (NotificationPreferenceService::CATEGORIES as $index => $category) {
            $lateAt = $base->addMinutes(2);
            $late = $this->candidate((int) $user->id, $category, $index + 101, $lateAt);
            $events->qualify($late, $preferences->enabledAt((int) $user->id, $category, $lateAt));
            self::assertSame('suppressed', NotificationEvent::query()->where('event_key', $events->buildKey($late))->firstOrFail()->visibility);
            $newAt = $base->addMinutes(4);
            $fresh = $this->candidate((int) $user->id, $category, $index + 201, $newAt);
            $events->qualify($fresh, $preferences->enabledAt((int) $user->id, $category, $newAt));
            self::assertSame('visible', NotificationEvent::query()->where('event_key', $events->buildKey($fresh))->firstOrFail()->visibility);
        }
    }

    #[Test]
    public function critical_stage_follows_its_category_preference(): void
    {
        $user = $this->signIn();
        $preferences = app(NotificationPreferenceService::class);
        $preferences->set((int) $user->id, 'credit_cards', false);
        $at = CarbonImmutable::now()->addSecond();
        $candidate = new NotificationCandidate((int) $user->id, 'statement_overdue', 'credit_cards', 'critical', 'credit_card_statement', 777,
            ['stage' => 'statement_overdue'], $at, ['title' => ['en' => 'Overdue'], 'summary' => ['en' => 'Review statement']]);
        app(NotificationEventService::class)->qualify($candidate, $preferences->enabledAt((int) $user->id, 'credit_cards', $at));
        self::assertSame('suppressed', NotificationEvent::query()->firstOrFail()->visibility);
    }

    private function signIn(): User
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        return $user;
    }

    private function candidate(int $userId, string $category, int $sourceId, CarbonImmutable $at): NotificationCandidate
    {
        [$type, $kind] = match ($category) {
            'credit_cards' => ['statement_due_today', 'credit_card_statement'],
            'recurring_transactions' => ['recurrence_review', 'transaction'],
            'budgets' => ['budget_approaching', 'budget_plan'],
            default => ['goal_reached', 'goal'],
        };

        return new NotificationCandidate($userId, $type, $category, 'attention', $kind, $sourceId, ['stage' => $type], $at,
            ['title' => ['en' => 'Review'], 'summary' => ['en' => 'Review item']]);
    }
}
