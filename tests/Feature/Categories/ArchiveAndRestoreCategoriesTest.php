<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use App\Models\Category;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ArchiveAndRestoreCategoriesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function owners_archive_and_restore_personal_categories_without_deleting_used_records(): void
    {
        $this->seed(CategorySeeder::class);
        $user = $this->signedInUser();
        $category = Category::factory()->used()->create(['user_id' => $user->id, 'name' => 'Pet care']);
        $this->postJson("/api/v1/categories/{$category->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonMissing(['id' => $category->id]);
        $this->getJson('/api/v1/categories?status=archived')->assertOk()->assertJsonFragment(['id' => $category->id, 'has_financial_transactions' => true]);
        $this->postJson("/api/v1/categories/{$category->id}/restore")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'has_financial_transactions' => true]);
    }

    #[Test]
    public function lifecycle_protects_defaults_privacy_conflicts_and_delete_route_absence(): void
    {
        $this->seed(CategorySeeder::class);
        $user = $this->signedInUser();
        $active = Category::factory()->create(['user_id' => $user->id, 'name' => 'Pet care']);
        $archived = Category::factory()->archived('PET CARE')->create(['user_id' => $user->id]);
        $system = Category::query()->where('origin', 'system')->firstOrFail();
        $other = Category::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->postJson("/api/v1/categories/{$archived->id}/restore")->assertStatus(409)->assertJsonPath('code', 'category_name_conflict');
        $this->postJson("/api/v1/categories/{$active->id}/archive")->assertOk();
        $this->postJson("/api/v1/categories/{$active->id}/archive")->assertStatus(409)->assertJsonPath('code', 'category_already_archived');
        $this->postJson("/api/v1/categories/{$system->id}/archive")->assertStatus(409)->assertJsonPath('code', 'category_system_read_only');
        $this->postJson("/api/v1/categories/{$other->id}/archive")->assertNotFound();
        $this->deleteJson("/api/v1/categories/{$active->id}")->assertMethodNotAllowed();
    }

    private function signedInUser(): User
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        return $user;
    }
}
