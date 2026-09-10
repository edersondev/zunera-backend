<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Categories\CategoryNameConflictException;
use App\Exceptions\Categories\CategoryStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Categories\ListCategoriesRequest;
use App\Http\Requests\Categories\StoreCategoryRequest;
use App\Http\Requests\Categories\UpdateCategoryRequest;
use App\Http\Resources\Categories\CategoryResource;
use App\Models\User;
use App\Services\Categories\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class CategoryController extends Controller
{
    public function index(ListCategoriesRequest $request, CategoryService $service): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return CategoryResource::collection($service->list($user, $request->status()));
    }

    public function store(StoreCategoryRequest $request, CategoryService $service): JsonResponse
    {
        try {
            $category = $service->create($request->toData());
        } catch (CategoryNameConflictException $exception) {
            return $this->conflictResponse($exception);
        }

        return (new CategoryResource($category))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(UpdateCategoryRequest $request, CategoryService $service, int $category_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new CategoryResource($service->findAvailable($user, $category_id)))->response();
    }

    public function update(UpdateCategoryRequest $request, CategoryService $service, int $category_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $category = $service->update($user, $service->findAvailable($user, $category_id), $request->toData());
        } catch (CategoryStateException|CategoryNameConflictException $exception) {
            return $this->conflictResponse($exception);
        }

        return (new CategoryResource($category))->response();
    }

    public function archive(UpdateCategoryRequest $request, CategoryService $service, int $category_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $category = $service->archive($user, $service->findAvailable($user, $category_id));
        } catch (CategoryStateException $exception) {
            return $this->conflictResponse($exception);
        }

        return (new CategoryResource($category))->response();
    }

    public function restore(UpdateCategoryRequest $request, CategoryService $service, int $category_id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $category = $service->restore($user, $service->findAvailable($user, $category_id));
        } catch (CategoryStateException|CategoryNameConflictException $exception) {
            return $this->conflictResponse($exception);
        }

        return (new CategoryResource($category))->response();
    }

    private function conflictResponse(CategoryStateException|CategoryNameConflictException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode()], Response::HTTP_CONFLICT);
    }
}
