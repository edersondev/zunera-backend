<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditCards\CreditCardCreditApplicationKind;
use App\Models\CreditCardCreditApplication;
use App\Models\CreditCardCreditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditCardCreditApplication> */
class CreditCardCreditApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'credit_card_credit_event_id' => CreditCardCreditEvent::factory(),
            'credit_card_id' => fn (array $attributes) => CreditCardCreditEvent::query()->find($attributes['credit_card_credit_event_id'])?->credit_card_id,
            'credit_card_installment_id' => null,
            'credit_card_statement_id' => null,
            'kind' => CreditCardCreditApplicationKind::Statement,
            'amount_centavos' => 5_000,
            'applied_at' => today()->toDateString(),
        ];
    }
}
