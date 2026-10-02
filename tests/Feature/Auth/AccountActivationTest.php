<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\AccountActivationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AccountActivationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_inactive_account_cannot_sign_in_or_access_protected_routes_until_activation(): void
    {
        Notification::fake();
        $user = $this->register('new@example.com');
        $token = $this->latestToken($user);

        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct horse battery staple',
        ])->assertForbidden()->assertJsonPath('code', 'account_inactive');

        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong password',
        ])->assertUnauthorized();

        $this->actingAs($user)->getJson('/api/v1/auth/session')
            ->assertForbidden()->assertJsonPath('code', 'account_inactive');

        Auth::guard('web')->logout();
        Auth::forgetGuards();

        $this->fromFrontend()->postJson('/api/v1/auth/activation/confirm', [
            'email' => ' NEW@example.com ',
            'token' => $token,
        ])->assertOk();

        $this->assertNotNull($user->fresh()?->email_verified_at);
        $this->assertDatabaseMissing('account_activation_tokens', ['user_id' => $user->id]);

        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct horse battery staple',
        ])->assertOk();

        $this->fromFrontend()->postJson('/api/v1/auth/activation/confirm', [
            'email' => $user->email,
            'token' => $token,
        ])->assertUnprocessable()->assertJsonPath('code', 'activation_link_invalid');
    }

    #[Test]
    public function expired_and_replaced_tokens_cannot_activate_an_account(): void
    {
        Notification::fake();
        $user = $this->register('expiring@example.com');
        $oldToken = $this->latestToken($user);

        $this->fromFrontend()->postJson('/api/v1/auth/activation/resend', ['email' => $user->email])
            ->assertAccepted();
        $newToken = $this->latestToken($user);
        $this->assertNotSame($oldToken, $newToken);

        $this->fromFrontend()->postJson('/api/v1/auth/activation/confirm', [
            'email' => $user->email,
            'token' => $oldToken,
        ])->assertUnprocessable()->assertJsonPath('code', 'activation_link_invalid');

        $this->travel(24)->hours();

        $this->fromFrontend()->postJson('/api/v1/auth/activation/confirm', [
            'email' => $user->email,
            'token' => $newToken,
        ])->assertUnprocessable()->assertJsonPath('code', 'activation_link_expired');

        $this->assertNull($user->fresh()?->email_verified_at);
    }

    #[Test]
    public function resend_is_neutral_and_limits_mail_per_address(): void
    {
        Notification::fake();
        $user = $this->register('resend@example.com');

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->fromFrontend()->postJson('/api/v1/auth/activation/resend', ['email' => $user->email])
                ->assertAccepted();
        }

        Notification::assertSentToTimes($user, AccountActivationNotification::class, 3);

        $this->fromFrontend()->postJson('/api/v1/auth/activation/resend', ['email' => 'unknown@example.com'])
            ->assertAccepted()->assertJsonPath('message', __('auth.activation_sent'));

        $activeUser = User::factory()->create(['email' => 'active@example.com']);
        $this->fromFrontend()->postJson('/api/v1/auth/activation/resend', ['email' => $activeUser->email])
            ->assertAccepted()->assertJsonPath('message', __('auth.activation_sent'));
        Notification::assertNothingSentTo($activeUser);
    }

    #[Test]
    public function malformed_token_is_rejected_without_changing_account(): void
    {
        Notification::fake();
        $user = $this->register('malformed@example.com');

        $this->fromFrontend()->postJson('/api/v1/auth/activation/confirm', [
            'email' => $user->email,
            'token' => 'bad',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->assertNull($user->fresh()?->email_verified_at);
        $this->assertSame(1, DB::table('account_activation_tokens')->where('user_id', $user->id)->count());
    }

    private function register(string $email): User
    {
        $this->fromFrontend()->postJson('/api/v1/auth/register', [
            'name' => 'Ana da Silva',
            'email' => $email,
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertCreated();

        return User::query()->where('email', $email)->firstOrFail();
    }

    private function latestToken(User $user): string
    {
        $notification = Notification::sent($user, AccountActivationNotification::class)->last();
        $query = parse_url($notification->toMail($user)->actionUrl, PHP_URL_QUERY);
        parse_str((string) $query, $parameters);

        return (string) $parameters['token'];
    }

    private function fromFrontend(): self
    {
        return $this
            ->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173');
    }
}
