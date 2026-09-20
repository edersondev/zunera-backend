<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CreditCards\CreditCardDashboardProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CreditCardDashboardController extends Controller
{
    public function show(Request $request, CreditCardDashboardProjectionService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $service->summary($user)]);
    }
}
