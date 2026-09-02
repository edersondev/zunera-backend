<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FinancialAccounts\FinancialAccountNameConflictException;
use App\Exceptions\FinancialAccounts\FinancialAccountStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialAccounts\ListFinancialAccountsRequest;
use App\Http\Requests\FinancialAccounts\StoreFinancialAccountRequest;
use App\Http\Requests\FinancialAccounts\UpdateFinancialAccountRequest;
use App\Http\Resources\FinancialAccounts\FinancialAccountResource;
use App\Models\User;
use App\Services\FinancialAccounts\FinancialAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class FinancialAccountController extends Controller
{
    public function index(ListFinancialAccountsRequest $request, FinancialAccountService $service): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return FinancialAccountResource::collection($service->list($user, $request->status()));
    }

    public function store(StoreFinancialAccountRequest $request, FinancialAccountService $service): JsonResponse
    {
        return (new FinancialAccountResource($service->create($request->toData())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(UpdateFinancialAccountRequest $request, FinancialAccountService $service, int $account_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new FinancialAccountResource($service->findOwned($user, $account_id)))->response();
    }

    public function update(UpdateFinancialAccountRequest $request, FinancialAccountService $service, int $account_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $account = $service->update($user, $service->findOwned($user, $account_id), $request->toData());
        } catch (FinancialAccountStateException|FinancialAccountNameConflictException $exception) {
            return $this->conflictResponse($exception);
        }

        return (new FinancialAccountResource($account))->response();
    }

    public function archive(UpdateFinancialAccountRequest $request, FinancialAccountService $service, int $account_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $account = $service->archive($user, $service->findOwned($user, $account_id));
        } catch (FinancialAccountStateException $exception) {
            return $this->conflictResponse($exception);
        }

        return (new FinancialAccountResource($account))->response();
    }

    public function restore(UpdateFinancialAccountRequest $request, FinancialAccountService $service, int $account_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $account = $service->restore($user, $service->findOwned($user, $account_id));
        } catch (FinancialAccountStateException|FinancialAccountNameConflictException $exception) {
            return $this->conflictResponse($exception);
        }

        return (new FinancialAccountResource($account))->response();
    }

    private function conflictResponse(FinancialAccountStateException|FinancialAccountNameConflictException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode(),
        ], Response::HTTP_CONFLICT);
    }
}
