<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\RecurringTransactions\RecurrenceStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecurringTransactions\ConfirmCardOccurrenceRequest;
use App\Http\Requests\RecurringTransactions\LifecycleRecurringTransactionRequest;
use App\Http\Requests\RecurringTransactions\ListRecurringTransactionsRequest;
use App\Http\Requests\RecurringTransactions\OccurrenceActionRequest;
use App\Http\Requests\RecurringTransactions\StoreRecurringTransactionRequest;
use App\Http\Requests\RecurringTransactions\UpdateRecurringTransactionRequest;
use App\Http\Resources\RecurringTransactions\CardOccurrenceResource;
use App\Http\Resources\RecurringTransactions\GeneratedOccurrenceResource;
use App\Http\Resources\RecurringTransactions\RecurringTransactionResource;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Services\RecurringTransactions\RecurringCardOccurrenceActionService;
use App\Services\RecurringTransactions\RecurringTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RecurringTransactionController extends Controller
{
    public function index(ListRecurringTransactionsRequest $request, RecurringTransactionService $service): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return RecurringTransactionResource::collection($service->list($user, $request->toData()));
    }

    public function store(StoreRecurringTransactionRequest $request, RecurringTransactionService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $result = $service->create($user, $request->toData(), $request->idempotencyKey());
        } catch (RecurrenceStateException $exception) {
            return $this->stateFailure($exception);
        }

        return $this->mutationResponse($result);
    }

    public function show(Request $request, RecurringTransactionService $service, int $recurring_transaction_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new RecurringTransactionResource($service->findOwned($user, $recurring_transaction_id)))->response();
    }

    public function update(UpdateRecurringTransactionRequest $request, RecurringTransactionService $service, int $recurring_transaction_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $result = $service->update(
                $user,
                $service->findOwned($user, $recurring_transaction_id),
                $request->toData(),
                $request->idempotencyKey(),
            );
        } catch (RecurrenceStateException $exception) {
            return $this->stateFailure($exception);
        }

        return $this->mutationResponse($result);
    }

    public function pause(LifecycleRecurringTransactionRequest $request, RecurringTransactionService $service, int $recurring_transaction_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $result = $service->pause($user, $service->findOwned($user, $recurring_transaction_id), $request->idempotencyKey());
        } catch (RecurrenceStateException $exception) {
            return $this->stateFailure($exception);
        }

        return $this->mutationResponse($result);
    }

    public function resume(LifecycleRecurringTransactionRequest $request, RecurringTransactionService $service, int $recurring_transaction_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $result = $service->resume($user, $service->findOwned($user, $recurring_transaction_id), $request->idempotencyKey());
        } catch (RecurrenceStateException $exception) {
            return $this->stateFailure($exception);
        }

        return $this->mutationResponse($result);
    }

    public function end(LifecycleRecurringTransactionRequest $request, RecurringTransactionService $service, int $recurring_transaction_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $result = $service->end($user, $service->findOwned($user, $recurring_transaction_id), $request->idempotencyKey());
        } catch (RecurrenceStateException $exception) {
            return $this->stateFailure($exception);
        }

        return $this->mutationResponse($result);
    }

    public function occurrences(Request $request, RecurringTransactionService $service, int $recurring_transaction_id): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(50, max(1, (int) $request->integer('per_page', 50)));
        $rule = $service->findOwned($user, $recurring_transaction_id);

        $occurrences = $service->listOccurrences($user, $rule, $page, $perPage);

        if ($rule->isCardDestination()) {
            return CardOccurrenceResource::collection($occurrences);
        }

        return GeneratedOccurrenceResource::collection($occurrences);
    }

    public function confirm(ConfirmCardOccurrenceRequest $request, RecurringTransactionService $service, RecurringCardOccurrenceActionService $actions, int $recurring_transaction_id, int $occurrence_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $rule = $service->findOwned($user, $recurring_transaction_id);
        $occurrence = $service->findOwnedOccurrence($user, $occurrence_id);

        if ((int) $occurrence->recurring_transaction_id !== (int) $rule->id) {
            return $this->stateFailure(RecurrenceStateException::occurrenceNotActionable());
        }

        try {
            $result = $actions->confirm($user, $rule, $occurrence, $request->toData());
        } catch (RecurrenceStateException $exception) {
            return $this->stateFailure($exception);
        }

        return (new CardOccurrenceResource($result['occurrence']))->response()->setStatusCode($result['status']);
    }

    public function dismiss(OccurrenceActionRequest $request, RecurringTransactionService $service, RecurringCardOccurrenceActionService $actions, int $recurring_transaction_id, int $occurrence_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $rule = $service->findOwned($user, $recurring_transaction_id);
        $occurrence = $service->findOwnedOccurrence($user, $occurrence_id);

        if ((int) $occurrence->recurring_transaction_id !== (int) $rule->id) {
            return $this->stateFailure(RecurrenceStateException::occurrenceNotActionable());
        }

        try {
            $result = $actions->dismiss($user, $rule, $occurrence);
        } catch (RecurrenceStateException $exception) {
            return $this->stateFailure($exception);
        }

        return (new CardOccurrenceResource($result['occurrence']))->response()->setStatusCode($result['status']);
    }

    public function retry(OccurrenceActionRequest $request, RecurringTransactionService $service, RecurringCardOccurrenceActionService $actions, int $recurring_transaction_id, int $occurrence_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $rule = $service->findOwned($user, $recurring_transaction_id);
        $occurrence = $service->findOwnedOccurrence($user, $occurrence_id);

        if ((int) $occurrence->recurring_transaction_id !== (int) $rule->id) {
            return $this->stateFailure(RecurrenceStateException::occurrenceNotActionable());
        }

        try {
            $result = $actions->retry($user, $rule, $occurrence);
        } catch (RecurrenceStateException $exception) {
            return $this->stateFailure($exception);
        }

        return (new CardOccurrenceResource($result['occurrence']))->response()->setStatusCode($result['status']);
    }

    /** @param array{rule: RecurringTransaction, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} $result */
    private function mutationResponse(array $result): JsonResponse
    {
        if ($result['replayed']) {
            return response()->json($result['response'])->setStatusCode($result['status']);
        }

        return (new RecurringTransactionResource($result['rule']))
            ->additional($result['meta'] === [] ? [] : ['meta' => $result['meta']])
            ->response()
            ->setStatusCode($result['status']);
    }

    private function stateFailure(RecurrenceStateException $exception): JsonResponse
    {
        $payload = ['message' => $exception->getMessage(), 'code' => $exception->errorCode()];
        if ($exception->errors() !== []) {
            $payload['errors'] = $exception->errors();
        }

        return response()->json($payload, $exception->statusCode());
    }
}
