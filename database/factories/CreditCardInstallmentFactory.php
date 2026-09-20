<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CreditCardInstallment;
use App\Models\CreditCardPurchase;
use App\Models\CreditCardStatement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditCardInstallment> */
class CreditCardInstallmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'credit_card_purchase_id' => CreditCardPurchase::factory(),
            'credit_card_id' => fn (array $attributes) => CreditCardPurchase::query()->find($attributes['credit_card_purchase_id'])?->credit_card_id,
            'credit_card_statement_id' => CreditCardStatement::factory(),
            'sequence' => 1,
            'amount_centavos' => 10_000,
            'credit_adjustment_centavos' => 0,
            'recognition_date' => null,
        ];
    }

    public function forPurchase(CreditCardPurchase $purchase, CreditCardStatement $statement, int $sequence = 1, ?int $amountCentavos = null): static
    {
        return $this->state([
            'user_id' => $purchase->user_id,
            'credit_card_purchase_id' => $purchase->id,
            'credit_card_id' => $purchase->credit_card_id,
            'credit_card_statement_id' => $statement->id,
            'sequence' => $sequence,
            'amount_centavos' => $amountCentavos ?? $purchase->total_amount_centavos,
            'recognition_date' => $statement->closing_date?->toDateString(),
        ]);
    }
}
