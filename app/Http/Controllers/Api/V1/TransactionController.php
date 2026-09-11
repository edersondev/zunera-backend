<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Transactions\TransactionStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\LifecycleTransactionRequest;
use App\Http\Requests\Transactions\ListTransactionsRequest;
use App\Http\Requests\Transactions\StoreTransactionRequest;
use App\Http\Requests\Transactions\UpdateTransactionRequest;
use App\Http\Resources\Transactions\TransactionResource;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class TransactionController extends Controller
{
    public function index(ListTransactionsRequest $request, TransactionService $service): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return TransactionResource::collection($service->list($user, $request->toData()));
    }

    public function store(StoreTransactionRequest $request, TransactionService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $service->create($user, $request->toData(), $request->idempotencyKey());

        return (new TransactionResource($result['transaction']))
            ->additional($result['meta'] === [] ? [] : ['meta' => $result['meta']])
            ->response()
            ->setStatusCode($result['status']);
    }

    public function show(Request $request, TransactionService $service, int $transaction_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new TransactionResource($service->findOwned($user, $transaction_id)))->response();
    }

    public function update(UpdateTransactionRequest $request, TransactionService $service, int $transaction_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $result = $service->update($user, $service->findOwned($user, $transaction_id), $request->toData(), $request->idempotencyKey());
        } catch (TransactionStateException $exception) {
            return $this->conflict($exception);
        }

        return $this->mutationResponse($result);
    }

    public function remove(LifecycleTransactionRequest $request, TransactionService $service, int $transaction_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $result = $service->remove($user, $service->findOwned($user, $transaction_id), $request->idempotencyKey());
        } catch (TransactionStateException $exception) {
            return $this->conflict($exception);
        }

        return $this->mutationResponse($result);
    }

    public function restore(LifecycleTransactionRequest $request, TransactionService $service, int $transaction_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $result = $service->restore($user, $service->findOwned($user, $transaction_id), $request->status(), $request->idempotencyKey());
        } catch (TransactionStateException $exception) {
            return $this->conflict($exception);
        }

        return $this->mutationResponse($result);
    }

    /** @param array{transaction: Transaction, status: int, meta: array<string, mixed>, replayed: bool} $result */
    private function mutationResponse(array $result): JsonResponse
    {
        return (new TransactionResource($result['transaction']))->additional($result['meta'] === [] ? [] : ['meta' => $result['meta']])->response()->setStatusCode($result['status']);
    }

    private function conflict(TransactionStateException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode()], Response::HTTP_CONFLICT);
    }
}
