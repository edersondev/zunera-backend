<?php

declare(strict_types=1);

namespace Tests\Unit\Authentication;

use App\Exceptions\LoginThrottledException;
use App\Services\Authentication\ProgressiveLoginLimiter;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProgressiveLoginLimiterTest extends TestCase
{
    #[Test]
    public function it_delays_after_five_failed_attempts_and_clears_on_success(): void
    {
        Cache::flush();

        $limiter = new ProgressiveLoginLimiter;

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $limiter->hit('person@example.com', '127.0.0.1');
        }

        $this->expectException(LoginThrottledException::class);
        $this->expectExceptionMessage('Too many sign-in attempts.');

        try {
            $limiter->hit('person@example.com', '127.0.0.1');
        } finally {
            $limiter->clear('person@example.com', '127.0.0.1');
            $limiter->ensureAllowed('person@example.com', '127.0.0.1');
        }
    }
}
