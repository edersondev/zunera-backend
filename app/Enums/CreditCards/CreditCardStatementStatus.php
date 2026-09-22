<?php

declare(strict_types=1);

namespace App\Enums\CreditCards;

enum CreditCardStatementStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public function acceptsPayment(): bool
    {
        return in_array($this, [self::Closed, self::PartiallyPaid, self::Overdue], true);
    }
}
