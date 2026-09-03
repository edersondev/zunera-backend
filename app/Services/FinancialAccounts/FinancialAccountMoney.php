<?php

declare(strict_types=1);

namespace App\Services\FinancialAccounts;

final class FinancialAccountMoney
{
    public const MIN_CENTAVOS = -999_999_999_999;

    public const MAX_CENTAVOS = 999_999_999_999;

    public static function isSupported(int $centavos): bool
    {
        return $centavos >= self::MIN_CENTAVOS && $centavos <= self::MAX_CENTAVOS;
    }

    public static function assertSupported(int $centavos): void
    {
        if (! self::isSupported($centavos)) {
            throw new \InvalidArgumentException('Monetary value is outside the supported Brazilian real range.');
        }
    }
}
