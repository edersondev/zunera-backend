<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\AccountActivationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_registers_an_inactive_account_without_a_session_and_queues_activation(): void
    {
        Notification::fake();

        $response = $this->fromFrontend()->postJson('/api/v1/auth/register', [
            'name' => '  Ana da Silva  ',
            'email' => ' NewUser@Example.COM ',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ]);

        $response->assertCreated()
            ->assertJsonPath('activation_required', true)
            ->assertJsonMissingPath('data.session');

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'name' => 'Ana da Silva',
            'email_verified_at' => null,
        ]);

        Notification::assertSentTo(User::query()->where('email', 'newuser@example.com')->firstOrFail(), AccountActivationNotification::class);
        $this->fromFrontend()->getJson('/api/v1/auth/session')->assertUnauthorized();
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
    public function it_rejects_seven_character_and_weak_passwords(): void
    {
        foreach (['A9!bcDe', 'qwerty12345'] as $password) {
            $this->fromFrontend()->postJson('/api/v1/auth/register', [
                'name' => 'Ana da Silva',
                'email' => 'person@example.com',
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
    }

    #[Test]
    public function it_rejects_passwords_based_on_registration_details(): void
    {
        foreach ([
            ['SilverCloud', 'person@example.com', 'SilverCloud2026!'],
            ['Ana da Silva', 'person@example.com', 'person@example.com2026!'],
        ] as [$name, $email, $password]) {
            $this->fromFrontend()->postJson('/api/v1/auth/register', [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
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
