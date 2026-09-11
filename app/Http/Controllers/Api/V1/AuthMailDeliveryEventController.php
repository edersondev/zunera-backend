<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreAuthenticationMailDeliveryEventRequest;
use App\Services\Authentication\AuthenticationMailDeliveryEvents;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AuthMailDeliveryEventController extends Controller
{
    public function store(StoreAuthenticationMailDeliveryEventRequest $request, AuthenticationMailDeliveryEvents $events): JsonResponse
    {
        $request->validateSignature();
        $events->record($request->toData());

        return response()->json(['message' => __('auth.mail_delivery_accepted')], Response::HTTP_ACCEPTED);
    }
}
