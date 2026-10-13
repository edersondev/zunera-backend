<?php

namespace App\Services\Categories;

use App\Services\Appearance\VisualColorPalette;

final class CategoryVisualOptions
{
    public const DEFAULT_COLOR = 'cyan';

    public const DEFAULT_ICON = 'circle';

    /** @return list<string> */
    public static function colors(): array
    {
        return VisualColorPalette::colors();
    }

    /** @return list<string> */
    public static function icons(): array
    {
        return ['home', 'utensils', 'car', 'heart', 'book', 'gamepad', 'shopping_bag', 'receipt', 'landmark', 'circle', 'wallet', 'briefcase', 'chart', 'gift', 'refund', 'bank', 'piggy_bank', 'credit_card', 'smartphone', 'cash', 'travel', 'subscription', 'utilities'];
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
