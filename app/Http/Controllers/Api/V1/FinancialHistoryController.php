<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialHistory\ListFinancialHistoryRequest;
use App\Models\User;
use App\Services\FinancialHistory\FinancialHistoryService;
use Illuminate\Http\JsonResponse;

final class FinancialHistoryController extends Controller
{
    /**
     * Canonical mixed read projection for existing financial-history screens.
     * The feature 004 transaction-only GET /transactions route stays owned by
     * TransactionController and is never registered a second time.
     */
    public function index(ListFinancialHistoryRequest $request, FinancialHistoryService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $paginator = $service->list($user, $request->toData());

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'totals' => $service->totals($user),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }
}
