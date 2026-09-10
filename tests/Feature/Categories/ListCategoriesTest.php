<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use App\Models\Category;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ListCategoriesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function active_lists_include_defaults_and_only_the_signed_in_users_personal_categories(): void
    {
        $this->seed(CategorySeeder::class);
        $user = $this->signedInUser();
        Category::factory()->create(['user_id' => $user->id, 'name' => 'Pet care']);
        Category::factory()->create(['user_id' => User::factory()->create()->id, 'name' => 'Private']);
        Category::factory()->archived('Old category')->create(['user_id' => $user->id]);

        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(17, 'data')->assertJsonFragment(['name' => 'Housing', 'origin' => 'system'])->assertJsonFragment(['name' => 'Pet care', 'origin' => 'personal'])->assertJsonMissing(['name' => 'Private']);
    }

    #[Test]
    public function archived_lists_only_return_the_signed_in_users_archived_personal_categories(): void
    {
        $this->seed(CategorySeeder::class);
        $user = $this->signedInUser();
        Category::factory()->archived('Old category')->create(['user_id' => $user->id]);
        Category::factory()->archived('Other private')->create(['user_id' => User::factory()->create()->id]);

        $this->getJson('/api/v1/categories?status=archived')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Old category');
    }

    #[Test]
    public function unauthenticated_and_invalid_list_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/categories')->assertUnauthorized();
        $this->signedInUser();
        $this->getJson('/api/v1/categories?status=inactive')->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    private function signedInUser(): User
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        return $user;
    }
}
