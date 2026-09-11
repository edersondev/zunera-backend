<?php

declare(strict_types=1);

namespace App\Services\Transactions;

final class TransactionMoney
{
    public const int MIN_CENTAVOS = 1;

    public const int MAX_CENTAVOS = 99_999_999_999;
}
