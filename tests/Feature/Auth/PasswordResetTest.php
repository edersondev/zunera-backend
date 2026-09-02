<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_resets_password_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'person@example.com',
            'password' => Hash::make('old correct battery staple'),
        ]);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'person@example.com',
            'token' => $token,
            'password' => 'new correct battery staple',
            'password_confirmation' => 'new correct battery staple',
        ])->assertOk()
            ->assertJsonPath('message', 'Password changed. You can sign in with your new password.');

        $this->assertTrue(Hash::check('new correct battery staple', $user->fresh()->password));
    }

    #[Test]
    public function it_returns_stable_invalid_recovery_code(): void
    {
        User::factory()->create(['email' => 'person@example.com']);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'person@example.com',
            'token' => 'not-a-token',
            'password' => 'new correct battery staple',
            'password_confirmation' => 'new correct battery staple',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'recovery_link_invalid');
    }
}
