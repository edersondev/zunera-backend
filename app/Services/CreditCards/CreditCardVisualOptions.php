<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

final class CreditCardVisualOptions
{
    public const DEFAULT_COLOR = 'violet';

    public const DEFAULT_ICON = 'credit_card';

    /** @return list<string> */
    public static function colors(): array
    {
        return ['teal', 'blue', 'violet', 'amber', 'rose', 'cyan'];
    }

    /** @return list<string> */
    public static function icons(): array
    {
        return ['credit_card', 'bank', 'wallet', 'smartphone', 'circle'];
    }

    public static function color(?string $color): string
    {
        return in_array($color, self::colors(), true) ? $color : self::DEFAULT_COLOR;
    }

    public static function icon(?string $icon): string
    {
        return in_array($icon, self::icons(), true) ? $icon : self::DEFAULT_ICON;
    }
}
