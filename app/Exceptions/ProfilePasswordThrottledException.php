<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ProfilePasswordThrottledException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct(__('auth.password_change_throttled'), 429);
    }
}
