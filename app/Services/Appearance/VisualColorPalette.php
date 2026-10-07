<?php

declare(strict_types=1);

namespace App\Services\Appearance;

final class VisualColorPalette
{
    /** @return list<string> */
    public static function colors(): array
    {
        return [
            'teal', 'blue', 'indigo', 'violet', 'purple', 'pink', 'rose', 'red',
            'orange', 'amber', 'lime', 'green', 'emerald', 'cyan', 'sky', 'slate',
        ];
    }
}
