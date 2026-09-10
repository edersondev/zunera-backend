<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Categories\CategoryClassification;
use App\Enums\Categories\CategoryOrigin;
use App\Enums\Categories\CategoryStatus;
use App\Models\Category;
use App\Services\Categories\CategoryNameNormalizer;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->defaults() as $category) {
            Category::query()->updateOrCreate(
                ['origin' => CategoryOrigin::System, 'classification' => $category['classification'], 'normalized_name' => CategoryNameNormalizer::normalize($category['name'])],
                ['user_id' => null, 'name' => $category['name'], 'color' => $category['color'], 'icon' => $category['icon'], 'status' => CategoryStatus::Active, 'archived_at' => null, 'has_financial_transactions' => false],
            );
        }
    }

    /** @return list<array{name: string, classification: CategoryClassification, color: string, icon: string}> */
    private function defaults(): array
    {
        return [
            ['name' => 'Housing', 'classification' => CategoryClassification::Expense, 'color' => 'teal', 'icon' => 'home'],
            ['name' => 'Food', 'classification' => CategoryClassification::Expense, 'color' => 'amber', 'icon' => 'utensils'],
            ['name' => 'Transportation', 'classification' => CategoryClassification::Expense, 'color' => 'blue', 'icon' => 'car'],
            ['name' => 'Health', 'classification' => CategoryClassification::Expense, 'color' => 'rose', 'icon' => 'heart'],
            ['name' => 'Education', 'classification' => CategoryClassification::Expense, 'color' => 'violet', 'icon' => 'book'],
            ['name' => 'Entertainment', 'classification' => CategoryClassification::Expense, 'color' => 'cyan', 'icon' => 'gamepad'],
            ['name' => 'Shopping', 'classification' => CategoryClassification::Expense, 'color' => 'violet', 'icon' => 'shopping_bag'],
            ['name' => 'Bills and utilities', 'classification' => CategoryClassification::Expense, 'color' => 'amber', 'icon' => 'receipt'],
            ['name' => 'Taxes', 'classification' => CategoryClassification::Expense, 'color' => 'rose', 'icon' => 'landmark'],
            ['name' => 'Other expenses', 'classification' => CategoryClassification::Expense, 'color' => 'cyan', 'icon' => 'circle'],
            ['name' => 'Salary', 'classification' => CategoryClassification::Income, 'color' => 'teal', 'icon' => 'wallet'],
            ['name' => 'Freelance or services', 'classification' => CategoryClassification::Income, 'color' => 'blue', 'icon' => 'briefcase'],
            ['name' => 'Investments', 'classification' => CategoryClassification::Income, 'color' => 'violet', 'icon' => 'chart'],
            ['name' => 'Gifts', 'classification' => CategoryClassification::Income, 'color' => 'amber', 'icon' => 'gift'],
            ['name' => 'Refunds', 'classification' => CategoryClassification::Income, 'color' => 'rose', 'icon' => 'refund'],
            ['name' => 'Other income', 'classification' => CategoryClassification::Income, 'color' => 'cyan', 'icon' => 'circle'],
        ];
    }
}
