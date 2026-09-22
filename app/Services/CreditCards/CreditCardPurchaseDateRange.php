<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class CreditCardPurchaseDateRange
{
    public const string MIN_DATE = '1900-01-01';

    public const string MAX_DATE = '2100-12-31';

    public static function normalize(string $value, string $field = 'purchase_date'): string
    {
        $date = self::parse($value, $field);

        return $date->toDateString();
    }

    public static function parse(string $value, string $field = 'purchase_date'): CarbonImmutable
    {
        $trimmed = trim($value);
        $normalized = preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1
            ? $trimmed
            : (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $trimmed) === 1
                ? CarbonImmutable::createFromFormat('d/m/Y', $trimmed)?->toDateString()
                : null);

        if ($normalized === null) {
            throw ValidationException::withMessages([$field => ['Use a valid calendar date.']]);
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $normalized, CreditCardMoney::BUSINESS_TIME_ZONE);

        if ($date === false || $date->toDateString() !== $normalized) {
            throw ValidationException::withMessages([$field => ['Use a valid calendar date.']]);
        }

        if ($date->lessThan(CarbonImmutable::parse(self::MIN_DATE)) || $date->greaterThan(CarbonImmutable::parse(self::MAX_DATE))) {
            throw ValidationException::withMessages([$field => ['Date must be between 1900-01-01 and 2100-12-31.']]);
        }

        return $date;
    }
}
