<?php

declare(strict_types=1);

namespace App\Enums\Transactions;

enum TransactionStatus: string
{
    case Pending = 'pending';
    case Effective = 'effective';
}
