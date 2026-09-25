<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\RecurrenceDestinationType;
use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\RecurringTransactions\RecurrencePausedReason;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionType;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecurringTransaction> */
class RecurringTransactionFactory extends Factory
{
    public function definition(): array
    {
        $start = today();

        return [
            'user_id' => User::factory(),
            'destination_type' => RecurrenceDestinationType::FinancialAccount,
            'financial_account_id' => FinancialAccount::factory(),
            'credit_card_id' => null,
            'generation_mode' => null,
            'category_id' => Category::factory(),
            'type' => TransactionType::Expense,
            'amount_centavos' => fake()->numberBetween(1_000, 500_000),
            'currency_code' => 'BRL',
            'description' => fake()->sentence(3),
            'notes' => null,
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => $start,
            'end_date' => null,
            'state' => RecurrenceState::Active,
            'paused_reason' => null,
            'eligibility_starts_on' => $start,
            'schedule_cursor' => $start,
            'ended_at' => null,
        ];
    }

    public function weekly(): static
    {
        return $this->state(['frequency' => RecurrenceFrequency::Weekly]);
    }

    public function monthly(): static
    {
        return $this->state(['frequency' => RecurrenceFrequency::Monthly]);
    }

    public function yearly(): static
    {
        return $this->state(['frequency' => RecurrenceFrequency::Yearly]);
    }

    public function paused(RecurrencePausedReason $reason = RecurrencePausedReason::User): static
    {
        return $this->state(['state' => RecurrenceState::Paused, 'paused_reason' => $reason]);
    }

    public function ended(): static
    {
        return $this->state(['state' => RecurrenceState::Ended, 'ended_at' => now()]);
    }

    public function withUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    /** Association whose account and category were archived after the rule was created. */
    public function archivedAssociation(): static
    {
        return $this
            ->for(FinancialAccount::factory()->archived(), 'financialAccount')
            ->for(Category::factory()->archived(), 'category');
    }

    /** Card destination with an active owned card and automatic mode. */
    public function card(CreditCard $card, ?CardGenerationMode $mode = null): static
    {
        return $this->state([
            'destination_type' => RecurrenceDestinationType::CreditCard,
            'financial_account_id' => null,
            'credit_card_id' => $card->id,
            'generation_mode' => $mode ?? CardGenerationMode::Automatic,
            'user_id' => $card->user_id,
        ]);
    }
}
