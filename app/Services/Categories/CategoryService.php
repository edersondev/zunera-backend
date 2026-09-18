<?php

declare(strict_types=1);

namespace App\Services\Categories;

use App\Data\Categories\CreateCategoryData;
use App\Data\Categories\UpdateCategoryData;
use App\Enums\Categories\CategoryClassification;
use App\Enums\Categories\CategoryOrigin;
use App\Enums\Categories\CategoryStatus;
use App\Exceptions\Categories\CategoryNameConflictException;
use App\Exceptions\Categories\CategoryStateException;
use App\Models\Category;
use App\Models\User;
use App\Services\RecurringTransactions\RecurringTransactionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CategoryService
{
    /** @return Collection<int, Category> */
    public function list(User $user, CategoryStatus $status): Collection
    {
        $query = Category::query()->orderBy('classification')->orderBy('name');

        if ($status->isArchived()) {
            return $query->where('origin', CategoryOrigin::Personal)->where('user_id', $user->id)->where('status', CategoryStatus::Archived)->get();
        }

        return $query->where('status', CategoryStatus::Active)->where(function ($categories) use ($user): void {
            $categories->where('origin', CategoryOrigin::System)->orWhere(function ($personal) use ($user): void {
                $personal->where('origin', CategoryOrigin::Personal)->where('user_id', $user->id);
            });
        })->get();
    }

    public function findAvailable(User $user, int $categoryId): Category
    {
        $category = Category::query()->where('id', $categoryId)->where(function ($categories) use ($user): void {
            $categories->where('origin', CategoryOrigin::System)->orWhere(function ($personal) use ($user): void {
                $personal->where('origin', CategoryOrigin::Personal)->where('user_id', $user->id);
            });
        })->first();

        if (! $category instanceof Category) {
            throw new NotFoundHttpException('Category not found or not accessible to the signed-in user.');
        }

        return $category;
    }

    public function create(CreateCategoryData $data): Category
    {
        $name = trim($data->name);
        $normalizedName = CategoryNameNormalizer::normalize($name);
        $this->assertNoSystemDefaultConflict($data->classification, $normalizedName);

        try {
            return DB::transaction(fn (): Category => Category::query()->create([
                'user_id' => $data->userId,
                'origin' => CategoryOrigin::Personal,
                'name' => $name,
                'normalized_name' => $normalizedName,
                'classification' => $data->classification,
                'color' => CategoryVisualOptions::color($data->color),
                'icon' => CategoryVisualOptions::icon($data->icon),
                'status' => CategoryStatus::Active,
                'archived_at' => null,
                'has_financial_transactions' => false,
            ]));
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw CategoryNameConflictException::activeNameConflict();
            }
            throw $exception;
        }
    }

    public function update(User $user, Category $category, UpdateCategoryData $data): Category
    {
        $this->assertMutable($user, $category);

        try {
            return DB::transaction(function () use ($category, $data): Category {
                $classification = $data->has('classification') ? $data->changes['classification'] : $category->classification;
                if ($data->has('classification') && $category->has_financial_transactions) {
                    throw CategoryStateException::classificationLocked();
                }
                // A budget plan association locks the classification as expense even
                // before the category has any financial transaction.
                if ($data->has('classification') && $classification !== $category->classification && $category->has_budget_plans) {
                    throw CategoryStateException::budgetPlanClassificationLocked();
                }
                $name = $data->has('name') ? trim((string) $data->changes['name']) : $category->name;
                $normalizedName = CategoryNameNormalizer::normalize($name);
                if ($category->status->isActive()) {
                    $this->assertNoActiveConflict($category, $classification, $normalizedName);
                }
                if ($data->has('name')) {
                    $category->name = $name;
                    $category->normalized_name = $normalizedName;
                }
                if ($data->has('classification')) {
                    $category->classification = $classification;
                }
                if ($data->has('color')) {
                    $category->color = CategoryVisualOptions::color($data->changes['color']);
                }
                if ($data->has('icon')) {
                    $category->icon = CategoryVisualOptions::icon($data->changes['icon']);
                }
                $category->save();

                return $category;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw CategoryNameConflictException::activeNameConflict();
            }

            throw $exception;
        }
    }

    public function archive(User $user, Category $category): Category
    {
        $this->assertMutable($user, $category);

        return DB::transaction(function () use ($category): Category {
            if ($category->status->isArchived()) {
                throw CategoryStateException::alreadyArchived();
            }
            $category->status = CategoryStatus::Archived;
            $category->archived_at = now();
            $category->save();

            // Archiving an association pauses its active recurring rules without touching history.
            app(RecurringTransactionService::class)->pauseForArchivedCategory((int) $category->id);

            return $category;
        });
    }

    public function restore(User $user, Category $category): Category
    {
        $this->assertMutable($user, $category);

        try {
            return DB::transaction(function () use ($category): Category {
                if ($category->status->isActive()) {
                    throw CategoryStateException::alreadyActive();
                }
                $this->assertNoActiveConflict($category, $category->classification, CategoryNameNormalizer::normalize($category->name));
                $category->status = CategoryStatus::Active;
                $category->archived_at = null;
                $category->save();

                return $category;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw CategoryNameConflictException::activeNameConflict();
            }

            throw $exception;
        }
    }

    private function assertMutable(User $user, Category $category): void
    {
        if ($category->origin->isSystem()) {
            throw CategoryStateException::readOnlySystemDefault();
        }
        if ((int) $category->user_id !== (int) $user->id) {
            throw new NotFoundHttpException('Category not found or not accessible to the signed-in user.');
        }
    }

    private function assertNoActiveConflict(Category $category, CategoryClassification $classification, string $normalizedName): void
    {
        $this->assertNoSystemDefaultConflict($classification, $normalizedName);
        $conflictExists = Category::query()->where('origin', CategoryOrigin::Personal)->where('user_id', $category->user_id)->where('status', CategoryStatus::Active)->where('classification', $classification)->where('normalized_name', $normalizedName)->where('id', '!=', $category->id)->exists();
        if ($conflictExists) {
            throw CategoryNameConflictException::activeNameConflict();
        }
    }

    private function assertNoSystemDefaultConflict(CategoryClassification $classification, string $normalizedName): void
    {
        if (Category::query()->where('origin', CategoryOrigin::System)->where('classification', $classification)->where('normalized_name', $normalizedName)->exists()) {
            throw CategoryNameConflictException::systemDefaultConflict();
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique constraint failed') || str_contains($message, 'duplicate entry') || str_contains($message, 'unique constraint');
    }
}
