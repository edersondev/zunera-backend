<?php

declare(strict_types=1);

namespace App\Enums\CreditCards;

enum CreditCardCreditEventReason: string
{
    case Cancellation = 'cancellation';
    case Refund = 'refund';
    case Correction = 'correction';
}
