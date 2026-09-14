<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use Illuminate\Support\Str;

final class TransferTextNormalizer
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
