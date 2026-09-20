<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCards\LifecycleCreditCardRequest;
use App\Http\Requests\CreditCards\ListCreditCardsRequest;
use App\Http\Requests\CreditCards\StoreCreditCardRequest;
use App\Http\Requests\CreditCards\UpdateCreditCardRequest;
use App\Http\Resources\CreditCards\CreditCardResource;
use App\Models\CreditCard;
use App\Models\User;
use App\Services\CreditCards\CreditCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CreditCardController extends Controller
{
    public function index(ListCreditCardsRequest $request, CreditCardService $service): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return CreditCardResource::collection($service->list($user, $request->view()));
    }

    public function store(StoreCreditCardRequest $request, CreditCardService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $service->create($user, $request->toData(), $request->idempotencyKey());

        return $this->mutationResponse($result);
    }

    public function show(Request $request, CreditCardService $service, int $card_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new CreditCardResource($service->findOwned($user, $card_id)))->response();
    }

    public function update(UpdateCreditCardRequest $request, CreditCardService $service, int $card_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $service->update($user, $service->findOwned($user, $card_id), $request->toData(), $request->idempotencyKey());

        return $this->mutationResponse($result);
    }

    public function archive(LifecycleCreditCardRequest $request, CreditCardService $service, int $card_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $service->archive($user, $service->findOwned($user, $card_id), $request->idempotencyKey());

        return $this->mutationResponse($result);
    }

    /** @param array{target_type: string, target_id: int, status: int, response: array<string, mixed>, replayed: bool} $result */
    private function mutationResponse(array $result): JsonResponse
    {
        if ($result['replayed']) {
            return response()->json($result['response'])->setStatusCode($result['status']);
        }

        /** @var CreditCard $card */
        $card = $result['card'] ?? CreditCard::query()->find($result['target_id']);

        return (new CreditCardResource($card))->response()->setStatusCode($result['status']);
    }
}
