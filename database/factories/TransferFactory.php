<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Transfers\TransferStatus;
use App\Models\FinancialAccount;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transfer> */
class TransferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source_financial_account_id' => FinancialAccount::factory(),
            'destination_financial_account_id' => FinancialAccount::factory(),
            'status' => TransferStatus::Effective,
            'description' => fake()->optional()->sentence(3),
            'notes' => fake()->optional()->sentence(),
            'amount_centavos' => fake()->numberBetween(1, 500_000),
            'currency_code' => 'BRL',
            'transfer_date' => today(),
            'search_text' => '',
            'removed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => TransferStatus::Pending]);
    }

    public function effective(): static
    {
        return $this->state(['status' => TransferStatus::Effective]);
    }

    public function removed(): static
    {
        return $this->state(['removed_at' => now()]);
    }

    public function future(): static
    {
        return $this->state(['transfer_date' => today()->addDay(), 'status' => TransferStatus::Pending]);
    }

    public function withUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    /** History whose source and destination accounts were archived after the transfer was recorded. */
    public function archivedSideHistory(): static
    {
        return $this
            ->for(FinancialAccount::factory()->archived(), 'sourceAccount')
            ->for(FinancialAccount::factory()->archived(), 'destinationAccount');
    }
}
