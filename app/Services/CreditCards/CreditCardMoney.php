<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

final class CreditCardMoney
{
    public const int MIN_LIMIT_CENTAVOS = 1;

    public const int MAX_LIMIT_CENTAVOS = 99_999_999_999;

    public const int MIN_AMOUNT_CENTAVOS = 1;

    public const int MAX_AMOUNT_CENTAVOS = 99_999_999_999;

    public const int MIN_INSTALLMENT_COUNT = 1;

    public const int MAX_INSTALLMENT_COUNT = 360;

    public const int MIN_BILLING_DAY = 1;

    public const int MAX_BILLING_DAY = 31;

    public const string BUSINESS_TIME_ZONE = 'America/Sao_Paulo';
}
