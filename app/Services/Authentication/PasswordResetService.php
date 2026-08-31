<?php

declare(strict_types=1);

namespace App\Services\Authentication;

use App\Data\Authentication\ResetPasswordData;
use App\Exceptions\AuthenticationException;
use App\Models\User;
use App\Notifications\Auth\PasswordChangedNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class PasswordResetService
{
    public function reset(ResetPasswordData $data): void
    {
        $status = Password::reset([
            'email' => $data->email,
            'token' => $data->token,
            'password' => $data->password,
            'password_confirmation' => $data->password,
        ], function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->notify(new PasswordChangedNotification);

            event(new PasswordReset($user));
        });

        if ($status === Password::PASSWORD_RESET) {
            return;
        }

        if ($this->hasExpiredTokenRecord($data->email)) {
            throw AuthenticationException::recoveryLinkExpired();
        }

        throw AuthenticationException::recoveryLinkInvalid();
    }

    private function hasExpiredTokenRecord(string $email): bool
    {
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if ($record === null || $record->created_at === null) {
            return false;
        }

        return now()->parse($record->created_at)->addMinutes((int) config('auth.passwords.users.expire', 60))->isPast();
    }
}
