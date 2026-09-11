<?php

declare(strict_types=1);

namespace App\Services\Authentication;

use App\Data\Authentication\LoginData;
use App\Data\Authentication\RegisterData;
use App\Exceptions\LoginThrottledException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthenticationService
{
    public function __construct(
        private readonly ProgressiveLoginLimiter $loginLimiter,
        private readonly AuthenticationAudit $audit,
    ) {}

    /**
     * @return array{user: User, idle_expires_at: CarbonImmutable, absolute_expires_at: CarbonImmutable}
     */
    public function register(RegisterData $data, Request $request): array
    {
        try {
            $user = DB::transaction(function () use ($data): User {
                return User::query()->create([
                    'name' => $data->name,
                    'email' => $data->email,
                    'password' => Hash::make($data->password),
                ]);
            });
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'email' => [__('validation.unique', ['attribute' => __('validation.attributes.email')])],
            ]);
        }

        $this->startSession($user, $request);
        $this->audit->record('registered', ['user_id' => $user->id]);

        return $this->sessionPayload($user, $request);
    }

    /**
     * @return array{user: User, idle_expires_at: CarbonImmutable, absolute_expires_at: CarbonImmutable}
     */
    public function login(LoginData $data, Request $request): array
    {
        $this->loginLimiter->ensureAllowed($data->email, $data->ipAddress);

        $user = User::query()->where('email', $data->email)->first();

        if (! $user instanceof User || ! Hash::check($data->password, $user->password)) {
            try {
                $this->loginLimiter->hit($data->email, $data->ipAddress);
            } catch (LoginThrottledException $exception) {
                throw $exception;
            }

            throw new AuthenticationException(__('auth.failed'));
        }

        $this->loginLimiter->clear($data->email, $data->ipAddress);
        $this->startSession($user, $request);
        $this->audit->record('login', ['user_id' => $user->id]);

        return $this->sessionPayload($user, $request);
    }

    /**
     * @return array{user: User, idle_expires_at: CarbonImmutable, absolute_expires_at: CarbonImmutable}
     */
    public function current(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException(__('auth.unauthenticated'));
        }

        return $this->sessionPayload($user, $request);
    }

    /**
     * @return array{user: User, idle_expires_at: CarbonImmutable, absolute_expires_at: CarbonImmutable}
     */
    public function continue(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException(__('auth.unauthenticated'));
        }

        $request->session()->put('auth_last_activity_at', CarbonImmutable::now()->timestamp);

        return $this->sessionPayload($user, $request);
    }

    public function logout(Request $request): void
    {
        $userId = $request->user()?->getAuthIdentifier();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();
        $this->audit->record('logout', ['user_id' => $userId]);
    }

    private function startSession(User $user, Request $request): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $now = CarbonImmutable::now();
        $request->session()->put('authenticated_at', $now->timestamp);
        $request->session()->put('auth_last_activity_at', $now->timestamp);
        $request->session()->put('absolute_expires_at', $now->addMinutes($this->absoluteMinutes())->timestamp);
    }

    /**
     * @return array{user: User, idle_expires_at: CarbonImmutable, absolute_expires_at: CarbonImmutable}
     */
    private function sessionPayload(User $user, Request $request): array
    {
        $lastActivity = (int) $request->session()->get('auth_last_activity_at', CarbonImmutable::now()->timestamp);
        $absoluteExpiry = (int) $request->session()->get('absolute_expires_at', CarbonImmutable::now()->addMinutes($this->absoluteMinutes())->timestamp);

        return [
            'user' => $user,
            'idle_expires_at' => CarbonImmutable::createFromTimestamp($lastActivity)->addMinutes($this->idleMinutes()),
            'absolute_expires_at' => CarbonImmutable::createFromTimestamp($absoluteExpiry),
        ];
    }

    private function idleMinutes(): int
    {
        return (int) config('authentication.session.idle_minutes', 15);
    }

    private function absoluteMinutes(): int
    {
        return (int) config('authentication.session.absolute_minutes', 480);
    }
}
