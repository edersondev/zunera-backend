<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditCards\CreditCardCreditEventReason;
use App\Models\CreditCardCreditEvent;
use App\Models\CreditCardPurchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditCardCreditEvent> */
class CreditCardCreditEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'credit_card_id' => fn (array $attributes) => CreditCardPurchase::query()->find($attributes['credit_card_purchase_id'])?->credit_card_id,
            'credit_card_purchase_id' => CreditCardPurchase::factory(),
            'reason' => CreditCardCreditEventReason::Refund,
            'amount_centavos' => 5_000,
            'currency_code' => 'BRL',
            'event_date' => today()->toDateString(),
            'notes' => null,
        ];
    }

    public function forPurchase(CreditCardPurchase $purchase, int $amountCentavos, CreditCardCreditEventReason $reason = CreditCardCreditEventReason::Refund): static
    {
        return $this->state([
            'user_id' => $purchase->user_id,
            'credit_card_id' => $purchase->credit_card_id,
            'credit_card_purchase_id' => $purchase->id,
            'amount_centavos' => $amountCentavos,
            'reason' => $reason,
        ]);
    }
}
