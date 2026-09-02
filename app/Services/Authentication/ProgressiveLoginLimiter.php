<?php

declare(strict_types=1);

namespace App\Services\Authentication;

use App\Exceptions\LoginThrottledException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class ProgressiveLoginLimiter
{
    private const MAX_ATTEMPTS_BEFORE_DELAY = 5;

    /**
     * @var array<int, int>
     */
    private const DELAYS = [60, 120, 240, 480, 900];

    public function ensureAllowed(string $email, string $ipAddress): void
    {
        $state = $this->state($email, $ipAddress);

        if (($state['locked_until'] ?? 0) > Carbon::now()->timestamp) {
            throw new LoginThrottledException((int) (($state['locked_until'] ?? 0) - Carbon::now()->timestamp));
        }
    }

    public function hit(string $email, string $ipAddress): void
    {
        $state = $this->state($email, $ipAddress);
        $attempts = (int) ($state['attempts'] ?? 0) + 1;
        $delayIndex = max(0, min($attempts - self::MAX_ATTEMPTS_BEFORE_DELAY, count(self::DELAYS) - 1));
        $lockedUntil = 0;

        if ($attempts >= self::MAX_ATTEMPTS_BEFORE_DELAY) {
            $lockedUntil = Carbon::now()->timestamp + self::DELAYS[$delayIndex];
        }

        Cache::put($this->key($email, $ipAddress), [
            'attempts' => $attempts,
            'locked_until' => $lockedUntil,
        ], now()->addMinutes(15));

        if ($lockedUntil > 0) {
            throw new LoginThrottledException($lockedUntil - Carbon::now()->timestamp);
        }
    }

    public function clear(string $email, string $ipAddress): void
    {
        Cache::forget($this->key($email, $ipAddress));
    }

    /**
     * @return array{attempts?: int, locked_until?: int}
     */
    private function state(string $email, string $ipAddress): array
    {
        $state = Cache::get($this->key($email, $ipAddress), []);

        return is_array($state) ? $state : [];
    }

    private function key(string $email, string $ipAddress): string
    {
        return 'auth:login:'.hash_hmac('sha256', $email.'|'.$ipAddress, (string) config('app.key'));
    }
}
