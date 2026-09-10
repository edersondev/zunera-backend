<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use App\Models\Category;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CategoryDefaultsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_the_sixteen_read_only_system_defaults_idempotently(): void
    {
        $this->seed(CategorySeeder::class);
        $this->seed(CategorySeeder::class);

        $this->assertSame(16, Category::query()->where('origin', 'system')->count());
        $this->assertDatabaseHas('categories', ['name' => 'Salary', 'origin' => 'system', 'classification' => 'income', 'status' => 'active', 'user_id' => null]);
        $this->assertDatabaseHas('categories', ['name' => 'Bills and utilities', 'origin' => 'system', 'classification' => 'expense', 'status' => 'active', 'user_id' => null]);
    }
}
