<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialDashboard\DashboardPeriodRequest;
use App\Http\Resources\FinancialDashboard\DashboardAccountsResource;
use App\Http\Resources\FinancialDashboard\DashboardSummaryResource;
use App\Http\Resources\FinancialDashboard\ExpenseDistributionResource;
use App\Http\Resources\FinancialDashboard\FinancialEvolutionResource;
use App\Http\Resources\FinancialDashboard\RecentActivityResource;
use App\Http\Resources\FinancialDashboard\UpcomingActivityResource;
use App\Models\User;
use App\Services\FinancialDashboard\DashboardAccountsService;
use App\Services\FinancialDashboard\DashboardEvolutionService;
use App\Services\FinancialDashboard\DashboardExpenseDistributionService;
use App\Services\FinancialDashboard\DashboardRecentActivityService;
use App\Services\FinancialDashboard\DashboardSummaryService;
use App\Services\FinancialDashboard\DashboardUpcomingActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only, owner-scoped financial dashboard. Each projection is independent so
 * a failing section never hides a reliable one on the client.
 */
final class FinancialDashboardController extends Controller
{
    public function summary(DashboardPeriodRequest $request, DashboardSummaryService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new DashboardSummaryResource($service->summary($user, $request->toData())))->response();
    }

    public function accounts(Request $request, DashboardAccountsService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new DashboardAccountsResource($service->overview($user)))->response();
    }

    public function expenseDistribution(DashboardPeriodRequest $request, DashboardExpenseDistributionService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new ExpenseDistributionResource($service->distribution($user, $request->toData())))->response();
    }

    public function evolution(DashboardPeriodRequest $request, DashboardEvolutionService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new FinancialEvolutionResource($service->evolution($user, $request->toData())))->response();
    }

    public function recentActivity(Request $request, DashboardRecentActivityService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new RecentActivityResource($service->recent($user)))->response();
    }

    public function upcomingActivity(Request $request, DashboardUpcomingActivityService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $projection = $service->upcoming($user);

        return response()->json([
            'data' => (new UpcomingActivityResource($projection))->resolve(),
            'meta' => [
                'from' => $projection['from'],
                'to' => $projection['to'],
            ],
        ]);
    }
}
