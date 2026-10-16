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
        $safeContext = array_intersect_key($context, array_flip([
            'user_id', 'archive_id', 'previous_archive_id', 'request_id', 'correlation_id',
        ]));

        Log::channel(config('logging.auth_channel', 'auth'))->info('auth.'.$event, $safeContext);
    }
}
