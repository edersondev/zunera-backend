<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\PasswordChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_requires_an_authenticated_session_for_both_actions(): void
    {
        $this->fromFrontend()->patchJson('/api/v1/auth/profile', ['name' => 'New Name'])->assertUnauthorized();
        $this->fromFrontend()->patchJson('/api/v1/auth/password', $this->passwordPayload())->assertUnauthorized();
    }

    #[Test]
    public function it_updates_only_the_current_users_name_and_returns_user_resource(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'person@example.com']);
        $other = User::factory()->create(['name' => 'Other Name']);

        $this->actingAs($user)->fromFrontend()->patchJson('/api/v1/auth/profile', ['name' => '  New Name  '])
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $user->id, 'name' => 'New Name', 'email' => 'person@example.com']]);

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame('Other Name', $other->fresh()->name);
        $this->fromFrontend()->getJson('/api/v1/auth/session')->assertOk()->assertJsonPath('data.user.name', 'New Name');
    }

    #[Test]
    public function it_rejects_email_and_other_unsupported_fields_and_invalid_names(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'person@example.com']);

        $this->actingAs($user)->fromFrontend()->patchJson('/api/v1/auth/profile', [
            'name' => 'New Name', 'email' => 'changed@example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->fromFrontend()->patchJson('/api/v1/auth/profile', [
            'name' => 'New Name', 'email' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        foreach (['', 'A', str_repeat('a', 256)] as $name) {
            $this->fromFrontend()->patchJson('/api/v1/auth/profile', ['name' => $name])
                ->assertUnprocessable()->assertJsonValidationErrors('name');
        }

        $this->fromFrontend()->patchJson('/api/v1/auth/profile', ['name' => 'New Name', 'role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertSame('Old Name', $user->fresh()->name);
        $this->assertSame('person@example.com', $user->fresh()->email);
    }

    #[Test]
    public function it_changes_password_keeps_current_session_and_revokes_others_and_reset_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => Hash::make('old correct battery staple')]);
        $oldHash = $user->password;
        Password::createToken($user);
        DB::table('sessions')->insert([
            'id' => 'other-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user)->fromFrontend()->patchJson('/api/v1/auth/password', $this->passwordPayload())
            ->assertNoContent();

        $updated = $user->fresh();
        $this->assertNotSame($oldHash, $updated->password);
        $this->assertTrue(Hash::check('new correct battery staple', $updated->password));
        $this->assertFalse(Hash::check('old correct battery staple', $updated->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Notification::assertSentTo($user, PasswordChangedNotification::class);
        $this->fromFrontend()->getJson('/api/v1/auth/session')->assertOk();
    }

    #[Test]
    public function it_rejects_wrong_current_password_and_limits_repeated_failures(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => Hash::make('old correct battery staple')]);
        RateLimiter::clear('auth:profile-password:'.$user->id);
        $payload = $this->passwordPayload();
        $payload['current_password'] = 'wrong password';

        $this->actingAs($user);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->fromFrontend()->withHeader('Accept-Language', 'en')
                ->patchJson('/api/v1/auth/password', $payload)
                ->assertUnprocessable()
                ->assertJsonPath('errors.current_password.0', 'The current password is incorrect.');
        }

        $this->fromFrontend()->patchJson('/api/v1/auth/password', $this->passwordPayload())
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertTrue(Hash::check('old correct battery staple', $user->fresh()->password));
        Notification::assertNothingSent();
        RateLimiter::clear('auth:profile-password:'.$user->id);
    }

    #[Test]
    public function it_reuses_password_safety_and_confirmation_rules(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old correct battery staple')]);
        $this->actingAs($user);

        foreach (['short', 'password123456'] as $invalid) {
            $this->fromFrontend()->patchJson('/api/v1/auth/password', $this->passwordPayload($invalid))
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }

        $payload = $this->passwordPayload();
        $payload['password_confirmation'] = 'different password value';
        $this->fromFrontend()->patchJson('/api/v1/auth/password', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('old correct battery staple', $user->fresh()->password));
    }

    /** @return array{current_password: string, password: string, password_confirmation: string} */
    private function passwordPayload(string $newPassword = 'new correct battery staple'): array
    {
        return [
            'current_password' => 'old correct battery staple',
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ];
    }

    private function fromFrontend(): self
    {
        return $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173');
    }
}
