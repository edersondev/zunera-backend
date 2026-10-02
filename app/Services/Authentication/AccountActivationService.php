<?php

declare(strict_types=1);

namespace App\Services\Authentication;

use App\Exceptions\AuthenticationException;
use App\Models\User;
use App\Notifications\Auth\AccountActivationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class AccountActivationService
{
    private const EXPIRY_HOURS = 24;

    public function __construct(private readonly AuthenticationAudit $audit) {}

    public function sendInitial(User $user, string $locale, string $ipAddress): void
    {
        $this->issue($user, $locale);
        $this->recordSend($user->email, $ipAddress);
    }

    public function resend(string $email, string $ipAddress, string $locale): void
    {
        DB::transaction(function () use ($email, $ipAddress, $locale): void {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();

            if (! $user instanceof User || $user->email_verified_at !== null) {
                return;
            }

            if (RateLimiter::tooManyAttempts($this->emailKey($email), 3)
                || RateLimiter::tooManyAttempts($this->ipKey($ipAddress), 20)) {
                $this->audit->record('activation_resend_limited');

                return;
            }

            $this->issue($user, $locale);
            $this->recordSend($email, $ipAddress);
            $this->audit->record('activation_resent', ['user_id' => $user->id]);
        });
    }

    public function confirm(string $email, string $token): void
    {
        DB::transaction(function () use ($email, $token): void {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();

            if (! $user instanceof User || $user->email_verified_at !== null) {
                throw AuthenticationException::activationLinkInvalid();
            }

            $record = DB::table('account_activation_tokens')
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($record === null || ! hash_equals($record->token_hash, hash('sha256', $token))) {
                throw AuthenticationException::activationLinkInvalid();
            }

            if (now()->greaterThanOrEqualTo(now()->parse($record->created_at)->addHours(self::EXPIRY_HOURS))) {
                throw AuthenticationException::activationLinkExpired();
            }

            $user->forceFill(['email_verified_at' => now()])->save();
            DB::table('account_activation_tokens')->where('user_id', $user->id)->delete();
            $this->audit->record('account_activated', ['user_id' => $user->id]);
        });
    }

    private function issue(User $user, string $locale): void
    {
        $token = bin2hex(random_bytes(32));

        DB::table('account_activation_tokens')->updateOrInsert(
            ['user_id' => $user->id],
            ['token_hash' => hash('sha256', $token), 'created_at' => now()],
        );

        $user->notify((new AccountActivationNotification($token))->locale($locale));
    }

    private function recordSend(string $email, string $ipAddress): void
    {
        RateLimiter::hit($this->emailKey($email), 3600);
        RateLimiter::hit($this->ipKey($ipAddress), 3600);
    }

    private function emailKey(string $email): string
    {
        return 'auth:activation:email:'.hash_hmac('sha256', $email, (string) config('app.key'));
    }

    private function ipKey(string $ipAddress): string
    {
        return 'auth:activation:ip:'.hash_hmac('sha256', $ipAddress, (string) config('app.key'));
    }
}
