<?php

declare(strict_types=1);

namespace App\Services\Authentication;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use ZxcvbnPhp\Zxcvbn;

final class StrongPasswordRule implements ValidationRule
{
    public function __construct(private readonly Zxcvbn $scorer = new Zxcvbn) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->scorer->passwordStrength((string) $value)['score'] < 3) {
            $fail(__('validation.password_weak'));
        }
    }
}
