<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditCards\CreditCardStatementStatus;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditCardStatement> */
class CreditCardStatementFactory extends Factory
{
    public function definition(): array
    {
        $closing = CarbonImmutable::now()->startOfMonth()->addMonth()->day(10);

        return [
            'user_id' => User::factory(),
            'credit_card_id' => CreditCard::factory(),
            'period_from' => $closing->subMonthNoOverflow()->day(11)->toDateString(),
            'period_to' => $closing->toDateString(),
            'closing_date' => $closing->toDateString(),
            'due_date' => $closing->day(17)->toDateString(),
            'original_amount_centavos' => 0,
            'credit_adjustment_centavos' => 0,
            'paid_centavos' => 0,
            'card_credit_applied_centavos' => 0,
            'status' => CreditCardStatementStatus::Open,
            'finalized_at' => null,
        ];
    }

    public function forCard(CreditCard $card): static
    {
        return $this->state(['user_id' => $card->user_id, 'credit_card_id' => $card->id]);
    }

    public function closing(string $closingDate, string $dueDate): static
    {
        $closing = CarbonImmutable::parse($closingDate);

        return $this->state([
            'period_from' => $closing->subMonthNoOverflow()->addDay()->toDateString(),
            'period_to' => $closing->toDateString(),
            'closing_date' => $closing->toDateString(),
            'due_date' => $dueDate,
        ]);
    }

    public function closed(): static
    {
        return $this->state(['status' => CreditCardStatementStatus::Closed, 'finalized_at' => now()]);
    }

    public function paid(): static
    {
        return $this->state(['status' => CreditCardStatementStatus::Paid, 'finalized_at' => now()]);
    }
}
