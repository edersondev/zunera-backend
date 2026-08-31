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
        return ['required', 'string', 'min:15', 'max:64', 'confirmed', new SafePasswordRule];
    }
}
