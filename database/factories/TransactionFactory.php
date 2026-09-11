<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Transactions\TransactionStatus;
use App\Enums\Transactions\TransactionType;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'financial_account_id' => FinancialAccount::factory(),
            'category_id' => Category::factory(),
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Effective,
            'description' => fake()->sentence(3),
            'notes' => fake()->optional()->sentence(),
            'amount_centavos' => fake()->numberBetween(1, 500_000),
            'currency_code' => 'BRL',
            'transaction_date' => today(),
            'search_text' => '',
            'removed_at' => null,
        ];
    }

    public function income(): static
    {
        return $this->state(['type' => TransactionType::Income]);
    }

    public function expense(): static
    {
        return $this->state(['type' => TransactionType::Expense]);
    }

    public function pending(): static
    {
        return $this->state(['status' => TransactionStatus::Pending]);
    }

    public function effective(): static
    {
        return $this->state(['status' => TransactionStatus::Effective]);
    }

    public function removed(): static
    {
        return $this->state(['removed_at' => now()]);
    }

    public function futureDated(): static
    {
        return $this->state(['transaction_date' => today()->addDay(), 'status' => TransactionStatus::Pending]);
    }

    public function withUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    public function archivedAccount(): static
    {
        return $this->for(FinancialAccount::factory()->archived(), 'financialAccount');
    }

    public function archivedCategory(): static
    {
        return $this->for(Category::factory()->archived(), 'category');
    }
}
