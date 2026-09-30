<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\NotificationPreferenceChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class NotificationPreferenceService
{
    public const CATEGORIES = [
        'credit_cards',
        'recurring_transactions',
        'budgets',
        'financial_goals',
    ];

    /** @return array<string, bool> */
    public function all(int $userId): array
    {
        $result = array_fill_keys(self::CATEGORIES, true);
        foreach (NotificationPreference::query()->where('user_id', $userId)->get() as $preference) {
            $result[$preference->category] = (bool) $preference->enabled;
        }

        return $result;
    }

    public function enabledAt(int $userId, string $category, CarbonImmutable $qualifiedAt): bool
    {
        $this->assertCategory($category);
        $change = NotificationPreferenceChange::query()
            ->where('user_id', $userId)
            ->where('category', $category)
            ->where('effective_at', '<=', $qualifiedAt->format('Y-m-d H:i:s.u'))
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();

        return $change === null || (bool) $change->enabled;
    }

    public function set(int $userId, string $category, bool $enabled, ?CarbonImmutable $effectiveAt = null): void
    {
        $this->assertCategory($category);
        DB::transaction(function () use ($userId, $category, $enabled, $effectiveAt): void {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $preference = NotificationPreference::query()
                ->where('user_id', $userId)
                ->where('category', $category)
                ->lockForUpdate()
                ->first();

            if (($preference === null && $enabled) || ($preference !== null && (bool) $preference->enabled === $enabled)) {
                return;
            }

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $userId, 'category' => $category],
                ['enabled' => $enabled],
            );
            NotificationPreferenceChange::query()->create([
                'user_id' => $userId,
                'category' => $category,
                'enabled' => $enabled,
                'effective_at' => $effectiveAt ?? CarbonImmutable::now(),
            ]);
        }, 3);
    }

    private function assertCategory(string $category): void
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('Unsupported notification category.');
        }
    }
}
