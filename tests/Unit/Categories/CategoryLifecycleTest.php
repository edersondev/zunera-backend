<?php

declare(strict_types=1);

namespace Tests\Unit\Categories;

use App\Exceptions\Categories\CategoryStateException;
use App\Models\Category;
use App\Models\User;
use App\Services\Categories\CategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CategoryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_transitions_owned_personal_categories_and_rejects_repeated_actions(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id]);
        $service = app(CategoryService::class);
        $this->assertTrue($service->archive($user, $category)->status->isArchived());
        $this->expectException(CategoryStateException::class);
        $service->archive($user, $category);
    }
}
