<?php

declare(strict_types=1);

namespace App\Services\RecurringTransactions;

use Illuminate\Support\Str;

final class RecurringTextNormalizer
{
    /** Trims user text and treats blank input as absent so optional notes stay null. */
    public static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** Trimmed, single-spaced copy used for recurrence text comparisons. */
    public static function normalize(?string ...$values): string
    {
        return Str::of(implode(' ', array_filter($values, static fn (?string $value): bool => $value !== null)))
            ->squish()
            ->value();
    }
}
