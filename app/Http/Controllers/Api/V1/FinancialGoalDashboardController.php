<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FinancialGoals\FinancialGoalSummaryResource;
use App\Services\FinancialGoals\FinancialGoalQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinancialGoalDashboardController extends Controller
{
    public function summary(Request $request, FinancialGoalQueryService $query): JsonResponse
    {
        return (new FinancialGoalSummaryResource($query->summary((int) $request->user()->id)))->response();
    }

    public function dashboard(Request $request, FinancialGoalQueryService $query): JsonResponse
    {
        return response()->json(['data' => $query->dashboardGoals((int) $request->user()->id)]);
    }
}
