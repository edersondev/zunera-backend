<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCards\DeleteCreditCardPurchaseRequest;
use App\Http\Requests\CreditCards\ListCreditCardPurchasesRequest;
use App\Http\Requests\CreditCards\ListCreditCardStatementOptionsRequest;
use App\Http\Requests\CreditCards\MoveCreditCardPurchaseRequest;
use App\Http\Requests\CreditCards\PreviewCreditCardPurchaseMoveRequest;
use App\Http\Requests\CreditCards\StoreCreditCardPurchaseRequest;
use App\Http\Requests\CreditCards\UpdateCreditCardPurchaseRequest;
use App\Http\Resources\CreditCards\CreditCardPurchaseDeleteResource;
use App\Http\Resources\CreditCards\CreditCardPurchaseMovePreviewResource;
use App\Http\Resources\CreditCards\CreditCardPurchaseMoveResource;
use App\Http\Resources\CreditCards\CreditCardPurchaseMoveResultResource;
use App\Http\Resources\CreditCards\CreditCardPurchaseResource;
use App\Http\Resources\CreditCards\CreditCardStatementOptionsResource;
use App\Models\CreditCardPurchase;
use App\Models\User;
use App\Services\CreditCards\CreditCardMutationIdempotencyService;
use App\Services\CreditCards\CreditCardPurchaseCorrectionService;
use App\Services\CreditCards\CreditCardPurchaseDeleteService;
use App\Services\CreditCards\CreditCardPurchaseMoveService;
use App\Services\CreditCards\CreditCardPurchaseService;
use App\Services\CreditCards\StatementCycleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CreditCardPurchaseController extends Controller
{
    public function statementOptions(
        ListCreditCardStatementOptionsRequest $request,
        CreditCardPurchaseService $purchases,
        StatementCycleResolver $resolver,
        int $card_id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $card = $purchases->findOwnedCard($user, $card_id);

        return (new CreditCardStatementOptionsResource($resolver->options(
            $card, $request->purchaseDate(), $request->fromMonth(), $request->limit(),
        )))->response();
    }

    public function index(ListCreditCardPurchasesRequest $request, CreditCardPurchaseService $service, int $card_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $card = $service->findOwnedCard($user, $card_id);
        $purchases = $service->list($user, $card, $request->page(), $request->perPage());

        return response()->json([
            'data' => CreditCardPurchaseResource::collection($purchases->items())->resolve(),
            'meta' => [
                'current_page' => $purchases->currentPage(),
                'last_page' => $purchases->lastPage(),
                'per_page' => $purchases->perPage(),
                'total' => $purchases->total(),
            ],
        ]);
    }

    public function store(StoreCreditCardPurchaseRequest $request, CreditCardPurchaseService $service, int $card_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $card = $service->findOwnedCard($user, $card_id);

        $result = $service->create($user, $card, $request->toData($card_id), $request->idempotencyKey());

        return $this->mutationResponse($result);
    }

    public function show(Request $request, CreditCardPurchaseService $service, int $purchase_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new CreditCardPurchaseResource($service->findOwned($user, $purchase_id)))->response();
    }

    public function update(
        UpdateCreditCardPurchaseRequest $request,
        CreditCardPurchaseService $purchases,
        CreditCardPurchaseCorrectionService $service,
        CreditCardMutationIdempotencyService $idempotency,
        int $purchase_id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $idempotency->assertNoDeletedReplay((int) $user->id, $request->idempotencyKey());
        $result = $service->update(
            $user,
            $purchases->findOwned($user, $purchase_id),
            $request->toData(),
            $request->overLimitConfirmed(),
            $request->expectedAvailableCreditCentavos(),
            $request->idempotencyKey(),
        );

        return $this->mutationResponse($result);
    }

    public function movePreview(PreviewCreditCardPurchaseMoveRequest $request, CreditCardPurchaseMoveService $service, int $purchase_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new CreditCardPurchaseMovePreviewResource($service->preview($user, $purchase_id, $request->toData())))->response();
    }

    public function move(MoveCreditCardPurchaseRequest $request, CreditCardPurchaseMoveService $service, int $purchase_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $service->move($user, $purchase_id, $request->toData(), $request->idempotencyKey());

        return (new CreditCardPurchaseMoveResultResource($result['response']['data']))->response()->setStatusCode($result['status']);
    }

    public function moves(Request $request, CreditCardPurchaseMoveService $service, int $purchase_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => CreditCardPurchaseMoveResource::collection($service->history($user, $purchase_id))->resolve()]);
    }

    public function destroy(DeleteCreditCardPurchaseRequest $request, CreditCardPurchaseDeleteService $service, int $purchase_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $service->delete($user, $purchase_id, $request->idempotencyKey());

        return (new CreditCardPurchaseDeleteResource($result['response']['data']))->response()->setStatusCode($result['status']);
    }

    /** @param array{target_type: string, target_id: int, status: int, response: array<string, mixed>, replayed: bool} $result */
    private function mutationResponse(array $result): JsonResponse
    {
        if ($result['replayed']) {
            return response()->json($result['response'])->setStatusCode($result['status']);
        }

        /** @var CreditCardPurchase $purchase */
        $purchase = $result['purchase'] ?? CreditCardPurchase::query()->find($result['target_id']);

        return (new CreditCardPurchaseResource($purchase))->response()->setStatusCode($result['status']);
    }
}
