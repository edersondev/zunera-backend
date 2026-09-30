<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\NotificationPreferenceChange;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotificationPreferenceTimelineTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function all_four_categories_default_to_enabled_and_invalid_category_fails(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationPreferenceService::class);
        self::assertSame([
            'credit_cards' => true,
            'recurring_transactions' => true,
            'budgets' => true,
            'financial_goals' => true,
        ], $service->all((int) $user->id));

        $this->expectException(\InvalidArgumentException::class);
        $service->enabledAt((int) $user->id, 'unknown', CarbonImmutable::now());
    }

    #[Test]
    public function first_qualification_uses_effective_setting_even_when_evaluated_later(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationPreferenceService::class);
        $off = CarbonImmutable::parse('2026-09-29 10:00:00', 'UTC');
        $on = $off->addHour();

        $service->set((int) $user->id, 'budgets', false, $off);
        $service->set((int) $user->id, 'budgets', true, $on);

        self::assertTrue($service->enabledAt((int) $user->id, 'budgets', $off->subSecond()));
        self::assertFalse($service->enabledAt((int) $user->id, 'budgets', $off->addMinute()));
        self::assertTrue($service->enabledAt((int) $user->id, 'budgets', $on->addMinute()));
        self::assertSame(2, NotificationPreferenceChange::query()->where('user_id', $user->id)->count());
    }
}
