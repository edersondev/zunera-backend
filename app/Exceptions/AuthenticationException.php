<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class AuthenticationException extends RuntimeException
{
    private function __construct(string $translationKey, private readonly string $errorCode, int $status)
    {
        parent::__construct(__($translationKey), $status);
    }

    public static function passwordSafetyUnavailable(): self
    {
        return new self('auth.password_safety_unavailable', 'password_safety_unavailable', 503);
    }

    public static function recoveryLinkExpired(): self
    {
        return new self('auth.recovery_link_expired', 'recovery_link_expired', 422);
    }

    public static function recoveryLinkInvalid(): self
    {
        return new self('auth.recovery_link_invalid', 'recovery_link_invalid', 422);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
