<?php

declare(strict_types=1);

namespace App\Services\Authentication;

use App\Data\Authentication\RecoveryRequestData;
use App\Models\User;
use Illuminate\Support\Facades\Password;

final class PasswordRecoveryService
{
    public function __construct(
        private readonly RecoveryEmailLimiter $limiter,
        private readonly AuthenticationAudit $audit,
    ) {}

    public function request(RecoveryRequestData $data): void
    {
        if (! $this->limiter->allow($data->email, $data->ipAddress)) {
            $this->audit->record('recovery_limited');

            return;
        }

        if (User::query()->where('email', $data->email)->exists()) {
            Password::sendResetLink(['email' => $data->email]);
        }

        $this->audit->record('recovery_requested');
    }
}
