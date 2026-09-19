<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Categories\CategoryClassification;
use App\Enums\Categories\CategoryOrigin;
use App\Models\BudgetCategoryPlan;
use App\Models\Category;
use App\Models\MonthlyBudget;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BudgetCategoryPlan> */
class BudgetCategoryPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'monthly_budget_id' => MonthlyBudget::factory(),
            'category_id' => Category::factory(),
            'planned_amount_centavos' => 100_000,
            'currency_code' => 'BRL',
            'category_name_snapshot' => fake()->unique()->words(2, true),
            'category_classification_snapshot' => CategoryClassification::Expense,
            'category_origin_snapshot' => CategoryOrigin::Personal,
            'category_color_snapshot' => 'teal',
            'category_icon_snapshot' => 'circle',
        ];
    }

    public function amount(int $plannedAmountCentavos): static
    {
        return $this->state(['planned_amount_centavos' => $plannedAmountCentavos]);
    }
}
