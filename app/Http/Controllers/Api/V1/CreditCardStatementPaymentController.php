<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCards\LifecycleCreditCardPaymentRequest;
use App\Http\Requests\CreditCards\StoreCreditCardStatementPaymentRequest;
use App\Http\Requests\CreditCards\UpdateCreditCardStatementPaymentRequest;
use App\Models\CreditCardStatementPayment;
use App\Models\User;
use App\Services\CreditCards\CreditCardStatementPaymentService;
use App\Services\CreditCards\CreditCardStatementService;
use Illuminate\Http\JsonResponse;

final class CreditCardStatementPaymentController extends Controller
{
    public function store(
        StoreCreditCardStatementPaymentRequest $request,
        CreditCardStatementService $statements,
        CreditCardStatementPaymentService $service,
        int $statement_id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $statement = $statements->findOwned($user, $statement_id);
        $result = $service->create($user, $statement, $request->toData(), $request->idempotencyKey());

        return $this->mutationResponse($result);
    }

    public function update(
        UpdateCreditCardStatementPaymentRequest $request,
        CreditCardStatementPaymentService $service,
        int $payment_id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $result = $service->update($user, $service->findOwned($user, $payment_id), $request->toData(), $request->idempotencyKey());

        return $this->mutationResponse($result);
    }

    public function remove(
        LifecycleCreditCardPaymentRequest $request,
        CreditCardStatementPaymentService $service,
        int $payment_id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $result = $service->remove($user, $service->findOwned($user, $payment_id), $request->idempotencyKey());

        return $this->mutationResponse($result);
    }

    public function restore(
        LifecycleCreditCardPaymentRequest $request,
        CreditCardStatementPaymentService $service,
        int $payment_id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $result = $service->restore($user, $service->findOwned($user, $payment_id), $request->status(), $request->idempotencyKey());

        return $this->mutationResponse($result);
    }

    /** @param array<string, mixed> $result */
    private function mutationResponse(array $result): JsonResponse
    {
        if ($result['replayed']) {
            return response()->json($result['response'])->setStatusCode($result['status']);
        }

        /** @var CreditCardStatementPayment $payment */
        $payment = $result['payment'] ?? CreditCardStatementPayment::query()->find($result['target_id']);
        $service = app(CreditCardStatementPaymentService::class);

        return response()->json(['data' => $service->payloadFor($payment)])->setStatusCode($result['status']);
    }
}
