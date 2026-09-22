<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditCards\CreditCardPaymentStatus;
use App\Models\CreditCardStatement;
use App\Models\CreditCardStatementPayment;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditCardStatementPayment> */
class CreditCardStatementPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'credit_card_statement_id' => CreditCardStatement::factory(),
            'credit_card_id' => fn (array $attributes) => CreditCardStatement::query()->find($attributes['credit_card_statement_id'])?->credit_card_id,
            'financial_account_id' => FinancialAccount::factory(),
            'amount_centavos' => 10_000,
            'currency_code' => 'BRL',
            'payment_date' => today()->toDateString(),
            'notes' => null,
            'status' => CreditCardPaymentStatus::Effective,
            'removed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => CreditCardPaymentStatus::Pending]);
    }

    public function removed(): static
    {
        return $this->state(['removed_at' => now()]);
    }
}
