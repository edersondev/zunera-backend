<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use InvalidArgumentException;

final class TransferMoney
{
    public const int MIN_CENTAVOS = 1;

    public const int MAX_CENTAVOS = 99_999_999_999;

    public static function isSupported(int $centavos): bool
    {
        return $centavos >= self::MIN_CENTAVOS && $centavos <= self::MAX_CENTAVOS;
    }

    public static function assertSupported(int $centavos): void
    {
        if (! self::isSupported($centavos)) {
            throw new InvalidArgumentException('Transfer amounts must be positive whole centavos within the supported Brazilian real range.');
        }
    }

    /**
     * Parses a Brazilian-formatted money input such as "R$ 1.234,56" into exact centavos.
     * Returns null when the input carries no numeric value.
     */
    public static function fromBrl(string $value): ?int
    {
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        $negative = str_starts_with($raw, '-');
        $normalized = (string) preg_replace('/[^0-9.,]/', '', str_replace(['R$', 'r$', "\u{00A0}"], '', $raw));
        if ($normalized === '') {
            return null;
        }

        $lastComma = strrpos($normalized, ',');
        $lastPeriod = strrpos($normalized, '.');
        $decimalIndex = max($lastComma === false ? -1 : $lastComma, $lastPeriod === false ? -1 : $lastPeriod);

        if ($decimalIndex === -1) {
            $whole = $normalized;
            $fraction = '00';
        } else {
            $whole = substr($normalized, 0, $decimalIndex);
            $fraction = substr($normalized, $decimalIndex + 1);
        }

        $integer = (int) preg_replace('/[.,]/', '', $whole === '' ? '0' : $whole);
        $cents = (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -($integer * 100 + $cents) : $integer * 100 + $cents;
    }
}
