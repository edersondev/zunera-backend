<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FinancialAccounts\FinancialAccountSummaryResource;
use App\Models\User;
use App\Services\FinancialAccounts\FinancialAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinancialAccountSummaryController extends Controller
{
    public function show(Request $request, FinancialAccountService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new FinancialAccountSummaryResource($service->summary($user)))->response();
    }
}
