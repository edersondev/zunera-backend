<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\AuthenticationException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->email_verified_at === null) {
            throw AuthenticationException::accountInactive();
        }

        return $next($request);
    }
}
