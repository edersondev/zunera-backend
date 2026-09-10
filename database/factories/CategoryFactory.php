<?php

namespace Database\Factories;

use App\Enums\Categories\CategoryClassification;
use App\Enums\Categories\CategoryOrigin;
use App\Enums\Categories\CategoryStatus;
use App\Models\Category;
use App\Models\User;
use App\Services\Categories\CategoryNameNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'user_id' => User::factory(),
            'origin' => CategoryOrigin::Personal,
            'name' => $name,
            'normalized_name' => CategoryNameNormalizer::normalize($name),
            'classification' => CategoryClassification::Expense,
            'color' => 'teal',
            'icon' => 'circle',
            'status' => CategoryStatus::Active,
            'archived_at' => null,
            'has_financial_transactions' => false,
        ];
    }

    public function system(string $name = 'Housing', CategoryClassification $classification = CategoryClassification::Expense): static
    {
        return $this->state(fn (): array => ['user_id' => null, 'origin' => CategoryOrigin::System, 'name' => $name, 'normalized_name' => CategoryNameNormalizer::normalize($name), 'classification' => $classification, 'status' => CategoryStatus::Active, 'archived_at' => null]);
    }

    public function archived(?string $name = null): static
    {
        return $this->state(function (array $attributes) use ($name): array {
            $resolvedName = $name ?? fake()->unique()->words(2, true);

            return ['name' => $resolvedName, 'normalized_name' => CategoryNameNormalizer::normalize($resolvedName), 'status' => CategoryStatus::Archived, 'archived_at' => now()];
        });
    }

    public function used(): static
    {
        return $this->state(fn (): array => ['has_financial_transactions' => true]);
    }
}
