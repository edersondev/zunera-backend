<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class AuthenticationException extends RuntimeException
{
    public static function passwordSafetyUnavailable(): self
    {
        return new self('Password safety validation is temporarily unavailable.', 503);
    }

    public static function recoveryLinkExpired(): self
    {
        return new self('This recovery link has expired.', 422);
    }

    public static function recoveryLinkInvalid(): self
    {
        return new self('This recovery link is invalid.', 422);
    }

    public function errorCode(): string
    {
        return match ($this->getMessage()) {
            'Password safety validation is temporarily unavailable.' => 'password_safety_unavailable',
            'This recovery link has expired.' => 'recovery_link_expired',
            'This recovery link is invalid.' => 'recovery_link_invalid',
            default => 'authentication_error',
        };
    }
}
