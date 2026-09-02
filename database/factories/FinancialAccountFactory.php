<?php

namespace Database\Factories;

use App\Enums\FinancialAccounts\AccountStatus;
use App\Enums\FinancialAccounts\AccountType;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\FinancialAccounts\FinancialAccountNameNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialAccount>
 */
class FinancialAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'normalized_name' => FinancialAccountNameNormalizer::normalize($name),
            'account_type' => AccountType::Checking,
            'institution_name' => fake()->optional()->company(),
            'color' => 'teal',
            'icon' => 'circle',
            'initial_balance_centavos' => 0,
            'current_balance_centavos' => 0,
            'currency_code' => 'BRL',
            'status' => AccountStatus::Active,
            'archived_at' => null,
            'has_financial_movements' => false,
        ];
    }

    public function archived(?string $name = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => $name ?? fake()->unique()->words(2, true),
            'normalized_name' => FinancialAccountNameNormalizer::normalize($name ?? $attributes['name']),
            'status' => AccountStatus::Archived,
            'archived_at' => now(),
        ]);
    }

    public function withMovements(): static
    {
        return $this->state(fn (): array => [
            'has_financial_movements' => true,
        ]);
    }
}
