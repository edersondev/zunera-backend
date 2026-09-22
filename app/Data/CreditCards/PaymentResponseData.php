<?php

declare(strict_types=1);

namespace App\Data\CreditCards;

use App\Models\CreditCardStatementPayment;

final class PaymentResponseData
{
    /** @return array<string, mixed> */
    public static function from(CreditCardStatementPayment $payment): array
    {
        $payment->loadMissing('financialAccount');

        return [
            'id' => $payment->id,
            'statement_id' => $payment->credit_card_statement_id,
            'financial_account' => [
                'id' => $payment->financialAccount->id,
                'name' => $payment->financialAccount->name,
                'status' => $payment->financialAccount->status->value,
            ],
            'amount' => CreditCardResponseData::money($payment->amount_centavos),
            'payment_date' => $payment->payment_date->toDateString(),
            'notes' => $payment->notes,
            'status' => $payment->status->value,
            'is_removed' => $payment->removed_at !== null,
        ];
    }
}
