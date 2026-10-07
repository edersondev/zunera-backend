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
        $expected = [
            'teal', 'blue', 'indigo', 'violet', 'purple', 'pink', 'rose', 'red',
            'orange', 'amber', 'lime', 'green', 'emerald', 'cyan', 'sky', 'slate',
        ];

        self::assertSame($expected, VisualColorPalette::colors());
        self::assertSame($expected, FinancialAccountVisualOptions::colors());
        self::assertSame($expected, CategoryVisualOptions::colors());
        self::assertSame($expected, CreditCardVisualOptions::colors());
    }
}
