<?php

declare(strict_types=1);

namespace App\Services\Authentication;

final class PasswordRules
{
    /**
     * @return array<int, mixed>
     */
    public function confirmed(): array
    {
        return ['bail', 'required', 'string', 'min:8', 'max:64', 'confirmed', new StrongPasswordRule, new SafePasswordRule];
    }
}
