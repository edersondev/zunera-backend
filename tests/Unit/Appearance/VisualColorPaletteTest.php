<?php

declare(strict_types=1);

namespace Tests\Unit\Appearance;

use App\Services\Appearance\VisualColorPalette;
use App\Services\Categories\CategoryVisualOptions;
use App\Services\CreditCards\CreditCardVisualOptions;
use App\Services\FinancialAccounts\FinancialAccountVisualOptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class VisualColorPaletteTest extends TestCase
{
    #[Test]
    public function all_three_resources_use_the_same_curated_color_values(): void
    {
        $selectable = [
            'blue', 'violet', 'pink', 'red', 'orange',
            'yellow', 'green', 'cyan', 'brown', 'gray',
        ];
        $accepted = [
            ...$selectable,
            'teal', 'indigo', 'purple', 'rose', 'amber',
            'lime', 'emerald', 'sky', 'slate',
        ];

        self::assertSame($selectable, VisualColorPalette::selectableColors());
        self::assertSame($accepted, VisualColorPalette::colors());
        self::assertSame($accepted, FinancialAccountVisualOptions::colors());
        self::assertSame($accepted, CategoryVisualOptions::colors());
        self::assertSame($accepted, CreditCardVisualOptions::colors());
        self::assertSame('cyan', FinancialAccountVisualOptions::color(null));
        self::assertSame('cyan', CategoryVisualOptions::color(null));
        self::assertSame('violet', CreditCardVisualOptions::color(null));
    }
}
