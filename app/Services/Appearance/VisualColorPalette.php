<?php

declare(strict_types=1);

namespace App\Services\Appearance;

final class VisualColorPalette
{
    private const array SELECTABLE_COLORS = [
        'blue', 'violet', 'pink', 'red', 'orange',
        'yellow', 'green', 'cyan', 'brown', 'gray',
    ];

    private const array LEGACY_COLORS = [
        'teal', 'indigo', 'purple', 'rose', 'amber',
        'lime', 'emerald', 'sky', 'slate',
    ];

    /** @return list<string> */
    public static function selectableColors(): array
    {
        return self::SELECTABLE_COLORS;
    }

    /** @return list<string> */
    public static function colors(): array
    {
        return [...self::SELECTABLE_COLORS, ...self::LEGACY_COLORS];
    }
}
