<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Budgets\BudgetStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budgets\CopyBudgetRequest;
use App\Http\Requests\Budgets\DestroyBudgetPlanRequest;
use App\Http\Requests\Budgets\ShowBudgetMonthRequest;
use App\Http\Requests\Budgets\StoreBudgetPlanRequest;
use App\Http\Requests\Budgets\StoreBudgetRequest;
use App\Http\Requests\Budgets\UpdateBudgetPlanRequest;
use App\Http\Resources\Budgets\BudgetMonthResource;
use App\Models\MonthlyBudget;
use App\Models\User;
use App\Services\Budgets\BudgetCalculationService;
use App\Services\Budgets\BudgetMonthResolver;
use App\Services\Budgets\BudgetService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin, owner-scoped budget boundary: requests validate, services decide, the
 * resource fixes the public shape.
 */
final class BudgetController extends Controller
{
    public function show(ShowBudgetMonthRequest $request, BudgetService $budgets, BudgetCalculationService $calculator, BudgetMonthResolver $resolver): BudgetMonthResource
    {
        /** @var User $user */
        $user = $request->user();
        $year = $request->year();
        $month = $request->month();
        $budget = $budgets->findOwnedMonth($user, $year, $month);

        return new BudgetMonthResource([
            'period' => $resolver->period($year, $month),
            'budget' => $budget,
            'calculation' => $budget instanceof MonthlyBudget ? $calculator->forMonth($user, $budget) : null,
        ]);
    }

    public function store(StoreBudgetRequest $request, BudgetService $budgets, BudgetCalculationService $calculator, BudgetMonthResolver $resolver): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $budget = $budgets->createMonth($request->toData());
        } catch (BudgetStateException $exception) {
            return $this->conflictResponse($exception);
        }

        return $this->monthResponse($user, $budget, $calculator, $resolver)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function storePlan(StoreBudgetPlanRequest $request, BudgetService $budgets, BudgetCalculationService $calculator, BudgetMonthResolver $resolver, int $budget_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $budget = $budgets->requireOwnedBudget($user, $budget_id);

        try {
            $budgets->addPlan($user, $budget, $request->toData((int) $budget->id));
        } catch (BudgetStateException $exception) {
            return $this->conflictResponse($exception);
        }

        return $this->monthResponse($user, $budget, $calculator, $resolver)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function updatePlan(UpdateBudgetPlanRequest $request, BudgetService $budgets, BudgetCalculationService $calculator, BudgetMonthResolver $resolver, int $budget_id, int $plan_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $budget = $budgets->requireOwnedBudget($user, $budget_id);

        try {
            $budgets->updatePlan($user, $budget, $request->toData((int) $budget->id, $plan_id));
        } catch (BudgetStateException $exception) {
            return $this->conflictResponse($exception);
        }

        return $this->monthResponse($user, $budget, $calculator, $resolver)->response();
    }

    public function destroyPlan(DestroyBudgetPlanRequest $request, BudgetService $budgets, BudgetCalculationService $calculator, BudgetMonthResolver $resolver, int $budget_id, int $plan_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $budget = $budgets->requireOwnedBudget($user, $budget_id);

        try {
            $budgets->removePlan($user, $budget, $plan_id);
        } catch (BudgetStateException $exception) {
            return $this->conflictResponse($exception);
        }

        return $this->monthResponse($user, $budget, $calculator, $resolver)->response();
    }

    private function monthResponse(User $user, MonthlyBudget $budget, BudgetCalculationService $calculator, BudgetMonthResolver $resolver): BudgetMonthResource
    {
        return new BudgetMonthResource([
            'period' => $resolver->period((int) $budget->budget_year, (int) $budget->budget_month),
            'budget' => $budget,
            'calculation' => $calculator->forMonth($user, $budget),
        ]);
    }

    public function copy(CopyBudgetRequest $request, BudgetService $budgets, BudgetCalculationService $calculator, BudgetMonthResolver $resolver, int $budget_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $source = $budgets->requireOwnedBudget($user, $budget_id);

        try {
            $destination = $budgets->copyMonth($user, $source, $request->toData((int) $source->id));
        } catch (BudgetStateException $exception) {
            return $this->conflictResponse($exception);
        }

        return $this->monthResponse($user, $destination, $calculator, $resolver)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    private function conflictResponse(BudgetStateException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode(),
        ], $exception->getCode() ?: Response::HTTP_CONFLICT);
    }
}
