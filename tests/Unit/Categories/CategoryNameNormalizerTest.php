<?php

declare(strict_types=1);

namespace Tests\Unit\Categories;

use App\Services\Categories\CategoryNameNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CategoryNameNormalizerTest extends TestCase
{
    #[Test]
    public function it_trims_collapses_spaces_and_ignores_case_and_accents(): void
    {
        $this->assertSame('pet care', CategoryNameNormalizer::normalize('  PÉT   Care  '));
    }
}
