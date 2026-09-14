<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Transfers\TransferStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transfers\LifecycleTransferRequest;
use App\Http\Requests\Transfers\ListTransfersRequest;
use App\Http\Requests\Transfers\StoreTransferRequest;
use App\Http\Requests\Transfers\UpdateTransferRequest;
use App\Http\Resources\Transfers\TransferResource;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Transfers\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TransferController extends Controller
{
    public function index(ListTransfersRequest $request, TransferService $service): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return TransferResource::collection($service->list($user, $request->toData()));
    }

    public function store(StoreTransferRequest $request, TransferService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $result = $service->create($user, $request->toData(), $request->idempotencyKey());
        } catch (TransferStateException $exception) {
            return $this->stateFailure($exception);
        }

        return $this->mutationResponse($result);
    }

    public function show(Request $request, TransferService $service, int $transfer_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new TransferResource($service->findOwned($user, $transfer_id)))->response();
    }

    public function update(UpdateTransferRequest $request, TransferService $service, int $transfer_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $result = $service->update($user, $service->findOwned($user, $transfer_id), $request->toData(), $request->idempotencyKey());
        } catch (TransferStateException $exception) {
            return $this->stateFailure($exception);
        }

        return $this->mutationResponse($result);
    }

    public function remove(LifecycleTransferRequest $request, TransferService $service, int $transfer_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $result = $service->remove($user, $service->findOwned($user, $transfer_id), $request->idempotencyKey());
        } catch (TransferStateException $exception) {
            return $this->stateFailure($exception);
        }

        return $this->mutationResponse($result);
    }

    public function restore(LifecycleTransferRequest $request, TransferService $service, int $transfer_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $result = $service->restore($user, $service->findOwned($user, $transfer_id), $request->status(), $request->idempotencyKey());
        } catch (TransferStateException $exception) {
            return $this->stateFailure($exception);
        }

        return $this->mutationResponse($result);
    }

    /** @param array{transfer: Transfer, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} $result */
    private function mutationResponse(array $result): JsonResponse
    {
        if ($result['replayed']) {
            return response()->json($result['response'])->setStatusCode($result['status']);
        }

        return (new TransferResource($result['transfer']))
            ->additional($result['meta'] === [] ? [] : ['meta' => $result['meta']])
            ->response()
            ->setStatusCode($result['status']);
    }

    private function stateFailure(TransferStateException $exception): JsonResponse
    {
        $payload = ['message' => $exception->getMessage(), 'code' => $exception->errorCode()];
        if ($exception->errors() !== []) {
            $payload['errors'] = $exception->errors();
        }

        return response()->json($payload, $exception->statusCode());
    }
}
