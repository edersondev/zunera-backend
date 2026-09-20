<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCards\ListCreditCardStatementsRequest;
use App\Http\Resources\CreditCards\CreditCardStatementDetailResource;
use App\Http\Resources\CreditCards\CreditCardStatementResource;
use App\Models\User;
use App\Services\CreditCards\CreditCardService;
use App\Services\CreditCards\CreditCardStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CreditCardStatementController extends Controller
{
    public function index(
        ListCreditCardStatementsRequest $request,
        CreditCardService $cards,
        CreditCardStatementService $service,
        int $card_id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $card = $cards->findOwned($user, $card_id);
        $statements = $service->list($user, $card, $request->status(), $request->page(), $request->perPage());

        return response()->json([
            'data' => CreditCardStatementResource::collection($statements->items())->resolve(),
            'meta' => [
                'current_page' => $statements->currentPage(),
                'last_page' => $statements->lastPage(),
                'per_page' => $statements->perPage(),
                'total' => $statements->total(),
            ],
        ]);
    }

    public function show(Request $request, CreditCardStatementService $service, int $statement_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new CreditCardStatementDetailResource($service->findOwned($user, $statement_id)))->response();
    }
}
