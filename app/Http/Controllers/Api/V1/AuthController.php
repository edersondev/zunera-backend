<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\RequestPasswordRecoveryRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\Auth\AuthenticatedSessionResource;
use App\Services\Authentication\AuthenticationService;
use App\Services\Authentication\PasswordRecoveryService;
use App\Services\Authentication\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthController extends Controller
{
    public function register(RegisterRequest $request, AuthenticationService $auth): JsonResponse
    {
        return (new AuthenticatedSessionResource($auth->register($request->toData(), $request)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request, AuthenticationService $auth): JsonResponse
    {
        return (new AuthenticatedSessionResource($auth->login($request->toData(), $request)))->response();
    }

    public function session(Request $request, AuthenticationService $auth): JsonResponse
    {
        return (new AuthenticatedSessionResource($auth->current($request)))->response();
    }

    public function continue(Request $request, AuthenticationService $auth): JsonResponse
    {
        $session = $auth->continue($request);

        return response()->json([
            'idle_expires_at' => $session['idle_expires_at']->toIso8601String(),
            'absolute_expires_at' => $session['absolute_expires_at']->toIso8601String(),
        ]);
    }

    public function logout(Request $request, AuthenticationService $auth): JsonResponse
    {
        $auth->logout($request);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function requestRecovery(RequestPasswordRecoveryRequest $request, PasswordRecoveryService $service): JsonResponse
    {
        $service->request($request->toData());

        return response()->json(['message' => 'If an account exists for that email, recovery instructions will be sent.'], Response::HTTP_ACCEPTED);
    }

    public function resetPassword(ResetPasswordRequest $request, PasswordResetService $service): JsonResponse
    {
        $service->reset($request->toData());

        return response()->json(['message' => 'Password changed. You can sign in with your new password.']);
    }
}
