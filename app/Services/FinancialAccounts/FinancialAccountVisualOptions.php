<?php

declare(strict_types=1);

namespace App\Services\FinancialAccounts;

final class FinancialAccountVisualOptions
{
    public const DEFAULT_COLOR = 'teal';

    public const DEFAULT_ICON = 'circle';

    /**
     * @return list<string>
     */
    public static function colors(): array
    {
        return ['teal', 'blue', 'violet', 'amber', 'rose', 'cyan'];
    }

    /**
     * @return list<string>
     */
    public static function icons(): array
    {
        return ['bank', 'piggy_bank', 'wallet', 'chart', 'smartphone', 'circle'];
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
