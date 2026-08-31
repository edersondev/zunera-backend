<?php

declare(strict_types=1);

namespace App\Services\Authentication;

use Illuminate\Support\Facades\Cache;

final class RecoveryEmailLimiter
{
    public function allow(string $email, string $ipAddress): bool
    {
        $key = $this->key($email, $ipAddress);
        $count = (int) Cache::get($key, 0);

        if ($count >= 3) {
            return false;
        }

        Cache::put($key, $count + 1, now()->addHour());

        return true;
    }

    private function key(string $email, string $ipAddress): string
    {
        return 'auth:recovery:'.hash_hmac('sha256', $email.'|'.$ipAddress, (string) config('app.key'));
    }
}
