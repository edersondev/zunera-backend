<?php

declare(strict_types=1);

namespace App\Enums\Transactions;

enum TransactionType: string
{
    case Income = 'income';
    case Expense = 'expense';

    public function balanceEffect(int $amountCentavos): int
    {
        return $this === self::Income ? $amountCentavos : -$amountCentavos;
    }
}
