<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use Illuminate\Support\Str;

final class TransactionTextNormalizer
{
    public static function normalize(?string ...$values): string
    {
        return Str::of(implode(' ', array_filter($values, static fn (?string $value): bool => $value !== null)))
            ->ascii()
            ->lower()
            ->squish()
            ->value();
    }
}
