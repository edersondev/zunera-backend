<?php

declare(strict_types=1);

namespace App\Services\RecurringTransactions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RecurringDateRange
{
    public const string MIN_DATE = '1900-01-01';

    public const string MAX_DATE = '2100-12-31';

    /** Zunera has no per-user time zone; recurrence eligibility follows the Brazilian business date. */
    public const string BUSINESS_TIMEZONE = 'America/Sao_Paulo';

    public static function normalize(string $value, string $field = 'start_date'): string
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
            } catch (Throwable) {
                continue;
            }
        }

        throw ValidationException::withMessages([
            $field => ['Provide a valid date from 1900-01-01 through 2100-12-31, using YYYY-MM-DD or DD/MM/YYYY.'],
        ]);
    }

    public static function businessDate(): string
    {
        return CarbonImmutable::now(self::BUSINESS_TIMEZONE)->toDateString();
    }

    public static function isFuture(string|DateTimeInterface $date): bool
    {
        $value = $date instanceof DateTimeInterface
            ? CarbonImmutable::instance($date)->toDateString()
            : $date;

        return $value > self::businessDate();
    }
}
