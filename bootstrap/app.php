<?php

use App\Exceptions\AuthenticationException as ZuneraAuthenticationException;
use App\Exceptions\LoginThrottledException;
use App\Http\Middleware\EnforceSessionLifetime;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'session.lifetime' => EnforceSessionLifetime::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $exception->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        });

        $exceptions->render(function (LoginThrottledException $exception, Request $request) {
            return response()
                ->json(['message' => 'Too many sign-in attempts.', 'code' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS)
                ->withHeaders(['Retry-After' => (string) $exception->retryAfter]);
        });

        $exceptions->render(function (ZuneraAuthenticationException $exception, Request $request) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode(),
            ], $exception->getCode() ?: Response::HTTP_BAD_REQUEST);
        });
    })->create();
