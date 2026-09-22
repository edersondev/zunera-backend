<?php

declare(strict_types=1);

namespace App\Enums\CreditCards;

enum CreditCardPaymentStatus: string
{
    case Pending = 'pending';
    case Effective = 'effective';
}
