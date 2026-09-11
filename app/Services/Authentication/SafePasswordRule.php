<?php

declare(strict_types=1);

namespace App\Services\Authentication;

use App\Exceptions\AuthenticationException;
use Closure;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\App;

final class SafePasswordRule implements ValidationRule
{
    /**
     * @var array<int, string>
     */
    private const COMMON_PASSWORDS = [
        'password',
        'password123',
        'password123456',
        '123456789012345',
        'qwerty123456789',
        'zunera123456789',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = (string) $value;

        if (in_array(mb_strtolower(trim($password)), self::COMMON_PASSWORDS, true)) {
            $fail(__('validation.password_common'));

            return;
        }

        if (App::environment('testing') && ! config('authentication.passwords.verify_uncompromised_in_tests', false)) {
            return;
        }

        try {
            $verified = app(UncompromisedVerifier::class)->verify([
                'value' => $password,
                'threshold' => 0,
            ]);
        } catch (\Throwable) {
            throw AuthenticationException::passwordSafetyUnavailable();
        }

        if (! $verified) {
            $fail(__('validation.password_compromised'));
        }
    }
}
