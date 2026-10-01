<?php

declare(strict_types=1);

namespace App\Services\Authentication;

use App\Exceptions\ProfilePasswordThrottledException;
use App\Models\User;
use App\Notifications\Auth\PasswordChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProfileService
{
    public function __construct(private readonly AuthenticationAudit $audit) {}

    public function updateName(User $user, string $name): User
    {
        if ($user->name !== $name) {
            $user->forceFill(['name' => $name])->save();
            $this->audit->record('profile_name_changed', ['user_id' => $user->id]);
        }

        return $user->refresh();
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword, string $currentSessionId): void
    {
        $key = 'auth:profile-password:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new ProfilePasswordThrottledException(RateLimiter::availableIn($key));
        }

        DB::transaction(function () use ($user, $currentPassword, $newPassword, $currentSessionId, $key): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! Hash::check($currentPassword, $locked->password)) {
                RateLimiter::hit($key, 900);
                throw ValidationException::withMessages([
                    'current_password' => [__('auth.current_password_invalid')],
                ]);
            }

            $locked->forceFill([
                'password' => Hash::make($newPassword),
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('password_reset_tokens')->where('email', $locked->email)->delete();
            DB::table('sessions')->where('user_id', $locked->id)->where('id', '!=', $currentSessionId)->delete();
            $locked->notify(new PasswordChangedNotification);
        });

        RateLimiter::clear($key);
        $this->audit->record('profile_password_changed', ['user_id' => $user->id]);
    }
}
