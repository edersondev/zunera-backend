<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LoginSessionLogoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_signs_in_reads_session_continues_and_logs_out_current_session(): void
    {
        User::factory()->create([
            'email' => 'person@example.com',
            'password' => Hash::make('correct horse battery staple'),
        ]);

        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => ' PERSON@example.com ',
            'password' => 'correct horse battery staple',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'person@example.com');

        $this->fromFrontend()->getJson('/api/v1/auth/session')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'person@example.com');

        $this->fromFrontend()->postJson('/api/v1/auth/session/continue')
            ->assertOk()
            ->assertJsonStructure(['idle_expires_at', 'absolute_expires_at']);

        $this->fromFrontend()->deleteJson('/api/v1/auth/session')->assertNoContent();
        $this->fromFrontend()->getJson('/api/v1/auth/session')->assertUnauthorized();
    }

    #[Test]
    public function it_returns_generic_invalid_credential_feedback(): void
    {
        User::factory()->create([
            'email' => 'person@example.com',
            'password' => Hash::make('correct horse battery staple'),
        ]);

        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => 'person@example.com',
            'password' => 'wrong password',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    #[Test]
    public function it_throttles_after_five_invalid_attempts(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->fromFrontend()->postJson('/api/v1/auth/login', [
                'email' => 'unknown@example.com',
                'password' => 'wrong password',
            ]);
        }

        $response->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    private function fromFrontend(): self
    {
        return $this
            ->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173');
    }
}
