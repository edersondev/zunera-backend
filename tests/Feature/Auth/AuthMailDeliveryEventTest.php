<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthMailDeliveryEventTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_accepts_signed_lowercase_uuid_delivery_events_idempotently(): void
    {
        config(['authentication.mail.delivery_secret' => 'test-secret']);
        $body = json_encode([
            'event_id' => 'evt_1234567890123456',
            'message_id' => '123e4567-e89b-12d3-a456-426614174000',
            'status' => 'delivered',
            'occurred_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'test-secret');

        $this->call('POST', '/api/v1/integrations/auth-mail/delivery-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZUNERA_TIMESTAMP' => $timestamp,
            'HTTP_X_ZUNERA_SIGNATURE' => $signature,
        ], $body)->assertAccepted();

        $this->call('POST', '/api/v1/integrations/auth-mail/delivery-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZUNERA_TIMESTAMP' => $timestamp,
            'HTTP_X_ZUNERA_SIGNATURE' => $signature,
        ], $body)->assertAccepted();

        $this->assertDatabaseCount('auth_mail_delivery_events', 1);
    }
}
