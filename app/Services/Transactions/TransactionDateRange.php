<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

final class TransactionDateRange
{
    public const string MIN_DATE = '1900-01-01';

    public const string MAX_DATE = '2100-12-31';

    public static function normalize(string $value, string $field = 'transaction_date'): string
    {
        $formats = ['!Y-m-d', '!d/m/Y'];

        foreach ($formats as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);
                if ($date !== false && $date->format(substr($format, 1)) === $value) {
                    $normalized = $date->toDateString();

                    if ($normalized >= self::MIN_DATE && $normalized <= self::MAX_DATE) {
                        return $normalized;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        throw ValidationException::withMessages([
            $field => ['Provide a valid date from 1900-01-01 through 2100-12-31, using YYYY-MM-DD or DD/MM/YYYY.'],
        ]);
    }

    public static function isFuture(string|DateTimeInterface $date): bool
    {
        $value = $date instanceof DateTimeInterface ? CarbonImmutable::instance($date) : CarbonImmutable::parse($date);

        return $value->isAfter(CarbonImmutable::today());
    }
}
