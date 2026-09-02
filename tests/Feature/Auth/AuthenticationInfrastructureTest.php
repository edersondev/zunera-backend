<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthenticationInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_uses_stateful_session_configuration_for_spa_authentication(): void
    {
        $this->assertContains('localhost:5173', config('sanctum.stateful'));
        $this->assertSame(15, config('session.lifetime'));
        $this->assertTrue(config('cors.supports_credentials'));
        $this->assertContains('http://localhost:5173', config('cors.allowed_origins'));
    }

    #[Test]
    public function it_returns_json_for_unauthenticated_api_requests(): void
    {
        $this->getJson('/api/v1/auth/session')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
