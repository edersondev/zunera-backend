<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FinancialGoals\FinancialGoalStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialGoals\CreateFinancialGoalRequest;
use App\Http\Requests\FinancialGoals\GoalLifecycleRequest;
use App\Http\Requests\FinancialGoals\GoalMoneyActionRequest;
use App\Http\Requests\FinancialGoals\UpdateFinancialGoalRequest;
use App\Http\Resources\FinancialGoals\FinancialGoalActivityResource;
use App\Http\Resources\FinancialGoals\FinancialGoalResource;
use App\Services\FinancialGoals\FinancialGoalMutationService;
use App\Services\FinancialGoals\FinancialGoalQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class FinancialGoalController extends Controller
{
    public function index(Request $request, FinancialGoalQueryService $query): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'completed', 'archived', 'all'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return FinancialGoalResource::collection($query->list((int) $request->user()->id, $data['status'] ?? 'active', (int) ($data['per_page'] ?? 20)));
    }

    public function store(CreateFinancialGoalRequest $request, FinancialGoalMutationService $service): JsonResponse
    {
        try {
            $result = $service->create((int) $request->user()->id, $request->toInput(), $request->idempotencyKey());
        } catch (FinancialGoalStateException $exception) {
            return $this->conflict($exception);
        }

        return response()->json($result['body'], $result['status']);
    }

    public function show(Request $request, FinancialGoalQueryService $query, int $goal_id): JsonResponse
    {
        return (new FinancialGoalResource($query->findOwned((int) $request->user()->id, $goal_id)))->response();
    }

    public function complete(GoalLifecycleRequest $request, FinancialGoalMutationService $service, int $goal_id): JsonResponse
    {
        return $this->lifecycle($request, $service, $goal_id, 'complete');
    }

    public function reopen(GoalLifecycleRequest $request, FinancialGoalMutationService $service, int $goal_id): JsonResponse
    {
        return $this->lifecycle($request, $service, $goal_id, 'reopen');
    }

    public function archive(GoalLifecycleRequest $request, FinancialGoalMutationService $service, int $goal_id): JsonResponse
    {
        return $this->lifecycle($request, $service, $goal_id, 'archive');
    }

    public function restore(GoalLifecycleRequest $request, FinancialGoalMutationService $service, int $goal_id): JsonResponse
    {
        return $this->lifecycle($request, $service, $goal_id, 'restore');
    }

    private function lifecycle(GoalLifecycleRequest $request, FinancialGoalMutationService $service, int $goalId, string $action): JsonResponse
    {
        try {
            $result = $service->lifecycle((int) $request->user()->id, $goalId, $action, $request->idempotencyKey());
        } catch (FinancialGoalStateException $exception) {
            return $this->conflict($exception);
        }

        return response()->json($result['body'], $result['status']);
    }

    public function update(UpdateFinancialGoalRequest $request, FinancialGoalMutationService $service, int $goal_id): JsonResponse
    {
        try {
            $result = $service->update((int) $request->user()->id, $goal_id, $request->toInput(), $request->idempotencyKey());
        } catch (FinancialGoalStateException $exception) {
            return $this->conflict($exception);
        }

        return response()->json($result['body'], $result['status']);
    }

    public function activities(Request $request, FinancialGoalQueryService $query, int $goal_id): AnonymousResourceCollection
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);

        return FinancialGoalActivityResource::collection($query->activities((int) $request->user()->id, $goal_id, (int) ($data['per_page'] ?? 20)));
    }

    public function allocate(GoalMoneyActionRequest $request, FinancialGoalMutationService $service, int $goal_id): JsonResponse
    {
        return $this->moneyAction($request, $service, $goal_id, 'allocated');
    }

    public function withdraw(GoalMoneyActionRequest $request, FinancialGoalMutationService $service, int $goal_id): JsonResponse
    {
        return $this->moneyAction($request, $service, $goal_id, 'withdrawn');
    }

    private function moneyAction(GoalMoneyActionRequest $request, FinancialGoalMutationService $service, int $goalId, string $type): JsonResponse
    {
        try {
            $result = $service->moneyAction((int) $request->user()->id, $goalId, $type, $request->amountCentavos(), $request->idempotencyKey());
        } catch (FinancialGoalStateException $exception) {
            return $this->conflict($exception);
        }

        return response()->json($result['body'], $result['status']);
    }

    private function conflict(FinancialGoalStateException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode()], 409);
    }
}
