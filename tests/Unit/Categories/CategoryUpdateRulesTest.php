<?php

declare(strict_types=1);

namespace Tests\Unit\Categories;

use App\Data\Categories\UpdateCategoryData;
use App\Enums\Categories\CategoryClassification;
use App\Exceptions\Categories\CategoryStateException;
use App\Models\Category;
use App\Models\User;
use App\Services\Categories\CategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CategoryUpdateRulesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function used_categories_lock_classification_but_allow_other_edits(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->used()->create(['user_id' => $user->id]);
        $service = app(CategoryService::class);
        $this->expectException(CategoryStateException::class);
        $service->update($user, $category, new UpdateCategoryData(['classification' => CategoryClassification::Income]));
    }
}
