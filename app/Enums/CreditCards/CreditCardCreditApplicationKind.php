<?php

declare(strict_types=1);

namespace App\Enums\CreditCards;

enum CreditCardCreditApplicationKind: string
{
    case Installment = 'installment';
    case Statement = 'statement';
}
