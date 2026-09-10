<?php

declare(strict_types=1);

namespace Tests\Unit\Categories;

use App\Enums\Categories\CategoryStatus;
use App\Models\Category;
use App\Models\User;
use App\Services\Categories\CategoryService;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CategoryListingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_composes_active_defaults_with_only_owned_personal_rows(): void
    {
        $this->seed(CategorySeeder::class);
        $user = User::factory()->create();
        Category::factory()->create(['user_id' => $user->id]);
        Category::factory()->create(['user_id' => User::factory()->create()->id]);

        $categories = app(CategoryService::class)->list($user, CategoryStatus::Active);
        $this->assertCount(17, $categories);
    }
}
