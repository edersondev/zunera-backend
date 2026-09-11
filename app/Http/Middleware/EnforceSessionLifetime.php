<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnforceSessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $now = CarbonImmutable::now()->timestamp;
        $lastActivity = (int) $request->session()->get('auth_last_activity_at', $now);
        $absoluteExpiry = (int) $request->session()->get('absolute_expires_at', $now + ($this->absoluteMinutes() * 60));

        if ($now >= $absoluteExpiry || $now >= ($lastActivity + ($this->idleMinutes() * 60))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Auth::forgetGuards();

            return response()->json(['message' => __('auth.session_expired'), 'code' => 'session_expired'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $request->is('api/v1/auth/session')) {
            $request->session()->put('auth_last_activity_at', $now);
        }

        return $next($request);
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
