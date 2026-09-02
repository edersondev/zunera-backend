<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SessionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_rejects_idle_expired_sessions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173')
            ->withSession([
                'auth_last_activity_at' => now()->subMinutes(16)->timestamp,
                'absolute_expires_at' => now()->addHour()->timestamp,
            ])
            ->getJson('/api/v1/auth/session')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'session_expired');
    }

    #[Test]
    public function it_rejects_absolute_expired_sessions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173')
            ->withSession([
                'auth_last_activity_at' => now()->timestamp,
                'absolute_expires_at' => now()->subSecond()->timestamp,
            ])
            ->getJson('/api/v1/auth/session')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'session_expired');
    }
}
