<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class LoginThrottledException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct(__('auth.throttled'), 429);
    }
}
