<?php

declare(strict_types=1);

namespace App\Services\Authentication;

final class PasswordRules
{
    /**
     * @param  array<int, string>  $userInputs
     * @return array<int, mixed>
     */
    public function confirmed(array $userInputs = []): array
    {
        return ['bail', 'required', 'string', 'min:8', 'max:64', 'confirmed', new StrongPasswordRule($userInputs), new SafePasswordRule];
    }
}
