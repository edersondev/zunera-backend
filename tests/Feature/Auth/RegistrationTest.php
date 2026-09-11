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
    public function it_registers_with_trimmed_name_and_returns_authenticated_session(): void
    {
        $response = $this->fromFrontend()->postJson('/api/v1/auth/register', [
            'name' => '  Ana da Silva  ',
            'email' => ' NewUser@Example.COM ',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.name', 'Ana da Silva')
            ->assertJsonPath('data.user.email', 'newuser@example.com')
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'session' => ['idle_expires_at', 'absolute_expires_at'],
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'name' => 'Ana da Silva',
        ]);
    }

    #[Test]
    public function it_rejects_duplicate_registration(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->fromFrontend()->postJson('/api/v1/auth/register', [
            'name' => 'Ana da Silva',
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
            'name' => 'Ana da Silva',
            'email' => 'person@example.com',
            'password' => 'password123456',
            'password_confirmation' => 'password123456',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    #[Test]
    public function it_rejects_missing_short_and_oversized_names(): void
    {
        foreach ([null, 'A', str_repeat('a', 256)] as $name) {
            $this->fromFrontend()->postJson('/api/v1/auth/register', [
                'name' => $name,
                'email' => 'person'.md5((string) $name).'@example.com',
                'password' => 'correct horse battery staple',
                'password_confirmation' => 'correct horse battery staple',
            ])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
    }

    #[Test]
    public function it_localizes_validation_errors_and_defaults_unsupported_locales_to_portuguese(): void
    {
        $this->fromFrontend()->withHeader('Accept-Language', 'en')->postJson('/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('errors.name.0', 'The full name field is required.');

        $this->fromFrontend()->withHeader('Accept-Language', 'fr-FR')->postJson('/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Os dados informados são inválidos.')
            ->assertJsonPath('errors.name.0', 'O campo nome completo é obrigatório.');
    }

    private function fromFrontend(): self
    {
        return $this
            ->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173');
    }
}
