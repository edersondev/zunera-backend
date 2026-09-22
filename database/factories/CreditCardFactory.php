<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditCards\CreditCardStatus;
use App\Models\CreditCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditCard> */
class CreditCardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->randomElement(['Nubank', 'Inter', 'Itaú', 'Bradesco']).' '.fake()->numberBetween(1, 999),
            'institution_name' => fake()->randomElement(['Nubank', 'Inter', 'Itaú', 'Bradesco']),
            'last_four' => fake()->optional()->numerify('####'),
            'color' => 'violet',
            'icon' => 'credit-card',
            'credit_limit_centavos' => fake()->numberBetween(10_000, 5_000_000),
            'closing_day' => 10,
            'due_day' => 17,
            'status' => CreditCardStatus::Active,
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => CreditCardStatus::Archived, 'archived_at' => now()]);
    }

    public function withUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    public function billingDays(int $closingDay, int $dueDay): static
    {
        return $this->state(['closing_day' => $closingDay, 'due_day' => $dueDay]);
    }
}
