<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MonthlyBudget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MonthlyBudget> */
class MonthlyBudgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'budget_year' => 2026,
            'budget_month' => 9,
        ];
    }
}
