<?php

declare(strict_types=1);

namespace Tests\Unit\Authentication;

use App\Services\Authentication\AuthenticationAudit;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class AuthenticationAuditTest extends TestCase
{
    #[Test]
    public function audit_keeps_identifiers_and_excludes_sensitive_context(): void
    {
        $channel = Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('info')->once()->with('auth.financial_data_restored', [
            'user_id' => 12,
            'archive_id' => 34,
            'previous_archive_id' => 22,
            'request_id' => 'request-56',
        ]);
        Log::shouldReceive('channel')->once()->with('auth')->andReturn($channel);

        app(AuthenticationAudit::class)->record('financial_data_restored', [
            'user_id' => 12,
            'archive_id' => 34,
            'previous_archive_id' => 22,
            'request_id' => 'request-56',
            'password' => 'secret-password',
            'access_token' => 'secret-access',
            'refresh_token' => 'secret-refresh',
            'session_id' => 'secret-session',
            'authorization' => 'Bearer secret',
            'headers' => ['Authorization' => 'Bearer secret'],
            'payload' => ['amount_centavos' => 10000],
        ]);
    }
}
