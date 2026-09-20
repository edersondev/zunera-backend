<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCards\StoreCreditCardCreditEventRequest;
use App\Models\User;
use App\Services\CreditCards\CreditCardCreditEventService;
use App\Services\CreditCards\CreditCardPurchaseService;
use Illuminate\Http\JsonResponse;

final class CreditCardCreditEventController extends Controller
{
    public function store(
        StoreCreditCardCreditEventRequest $request,
        CreditCardPurchaseService $purchases,
        CreditCardCreditEventService $service,
        int $purchase_id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $purchase = $purchases->findOwned($user, $purchase_id);
        $result = $service->record($user, $purchase, $request->toData(), $request->idempotencyKey());

        return response()->json($result['response'], 201);
    }
}
