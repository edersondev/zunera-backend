<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use App\Models\BudgetCategoryPlan;
use App\Models\Category;
use App\Models\MonthlyBudget;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CreateAndUpdateCategoriesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function users_create_and_update_personal_categories_with_permitted_visuals(): void
    {
        $this->seed(CategorySeeder::class);
        $this->signedInUser();
        $created = $this->postJson('/api/v1/categories', ['name' => '  Pet care ', 'classification' => 'expense', 'color' => 'blue', 'icon' => 'heart'])->assertCreated()->assertJsonPath('data.name', 'Pet care')->assertJsonPath('data.origin', 'personal')->assertJsonPath('data.color', 'blue')->json('data.id');
        $this->patchJson("/api/v1/categories/{$created}", ['name' => 'Pet health', 'classification' => 'income', 'icon' => 'gift'])->assertOk()->assertJsonPath('data.name', 'Pet health')->assertJsonPath('data.classification', 'income');
    }

    #[Test]
    public function duplicate_default_and_invalid_configurations_return_safe_feedback(): void
    {
        $this->seed(CategorySeeder::class);
        $user = $this->signedInUser();
        Category::factory()->create(['user_id' => $user->id, 'name' => 'Pet care']);
        $this->postJson('/api/v1/categories', ['name' => ' PET   CARE ', 'classification' => 'expense'])->assertStatus(409)->assertJsonPath('code', 'category_name_conflict');
        $this->postJson('/api/v1/categories', ['name' => 'Food', 'classification' => 'expense'])->assertStatus(409)->assertJsonPath('code', 'category_system_default_conflict');
        $this->postJson('/api/v1/categories', ['name' => ' ', 'classification' => 'transfer', 'color' => 'green'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'classification', 'color']);
    }

    #[Test]
    public function duplicate_category_updates_return_safe_conflict_feedback(): void
    {
        $user = $this->signedInUser();
        $existing = Category::factory()->create(['user_id' => $user->id, 'name' => 'Pet care']);
        $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Pet health']);

        $this->patchJson("/api/v1/categories/{$category->id}", ['name' => $existing->name])
            ->assertStatus(409)
            ->assertJsonPath('code', 'category_name_conflict');
    }

    #[Test]
    public function system_and_used_categories_are_protected_and_other_users_are_not_found(): void
    {
        $this->seed(CategorySeeder::class);
        $user = $this->signedInUser();
        $system = Category::query()->where('origin', 'system')->firstOrFail();
        $used = Category::factory()->used()->create(['user_id' => $user->id]);
        $other = Category::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->patchJson("/api/v1/categories/{$system->id}", ['name' => 'Nope'])->assertStatus(409)->assertJsonPath('code', 'category_system_read_only');
        $this->patchJson("/api/v1/categories/{$used->id}", ['classification' => 'income'])->assertStatus(409)->assertJsonPath('code', 'category_classification_locked');
        $this->patchJson("/api/v1/categories/{$used->id}", ['name' => 'Renamed'])->assertOk();
        $this->patchJson("/api/v1/categories/{$other->id}", ['name' => 'Nope'])->assertNotFound();
    }

    #[Test]
    public function a_category_used_in_a_budget_plan_cannot_become_income(): void
    {
        $user = $this->signedInUser();
        $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Pet care']);
        $budget = MonthlyBudget::factory()->create(['user_id' => $user->id, 'budget_year' => 2026, 'budget_month' => 9]);
        BudgetCategoryPlan::factory()->create(['monthly_budget_id' => $budget->id, 'category_id' => $category->id]);

        $this->patchJson("/api/v1/categories/{$category->id}", ['classification' => 'income'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'category_budget_plan_locked');

        // Renaming stays allowed while the classification lock holds.
        $this->patchJson("/api/v1/categories/{$category->id}", ['name' => 'Pet health'])->assertOk();
        self::assertSame('expense', $category->fresh()->classification->value);

        // Removing the plan never unlocks the classification.
        BudgetCategoryPlan::query()->where('category_id', $category->id)->delete();
        $this->patchJson("/api/v1/categories/{$category->id}", ['classification' => 'income'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'category_budget_plan_locked');
    }

    private function signedInUser(): User
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        return $user;
    }
}
