<?php

declare(strict_types=1);

namespace App\Data\CreditCards;

use App\Models\CreditCardCreditApplication;
use App\Models\CreditCardCreditEvent;

final class CreditEventResponseData
{
    /** @return array<string, mixed> */
    public static function from(CreditCardCreditEvent $event): array
    {
        $event->loadMissing('applications');

        return [
            'id' => $event->id,
            'reason' => $event->reason->value,
            'amount' => CreditCardResponseData::money($event->amount_centavos),
            'event_date' => $event->event_date->toDateString(),
            'notes' => $event->notes,
            'applications' => $event->applications
                ->map(fn (CreditCardCreditApplication $application) => [
                    'installment_id' => $application->credit_card_installment_id,
                    'statement_id' => $application->credit_card_statement_id,
                    'amount' => CreditCardResponseData::money($application->amount_centavos),
                ])
                ->all(),
        ];
    }
}
