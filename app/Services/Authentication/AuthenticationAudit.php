<?php

declare(strict_types=1);

namespace App\Services\Authentication;

use Illuminate\Support\Facades\Log;

final class AuthenticationAudit
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $event, array $context = []): void
    {
        unset($context['password'], $context['token'], $context['password_confirmation']);

        Log::channel(config('logging.auth_channel', 'stack'))->info('auth.'.$event, $context);
    }
}
