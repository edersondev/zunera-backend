<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_registers_with_normalized_email_and_returns_allow_listed_session(): void
    {
        $response = $this->fromFrontend()->postJson('/api/v1/auth/register', [
            'email' => ' NewUser@Example.COM ',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'newuser@example.com')
            ->assertJsonMissingPath('data.user.name')
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'email'],
                    'session' => ['idle_expires_at', 'absolute_expires_at'],
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'name' => null,
        ]);
    }

    #[Test]
    public function it_rejects_duplicate_registration(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->fromFrontend()->postJson('/api/v1/auth/register', [
            'email' => 'TAKEN@example.com',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function it_rejects_short_and_common_passwords(): void
    {
        $this->fromFrontend()->postJson('/api/v1/auth/register', [
            'email' => 'person@example.com',
            'password' => 'password123456',
            'password_confirmation' => 'password123456',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    private function fromFrontend(): self
    {
        return $this
            ->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173');
    }
}
