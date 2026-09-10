<?php

declare(strict_types=1);

namespace Tests\Unit\Categories;

use App\Services\Categories\CategoryVisualOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CategoryVisualOptionsTest extends TestCase
{
    #[Test]
    public function it_only_applies_permitted_categorical_visual_options(): void
    {
        $this->assertSame('teal', CategoryVisualOptions::color('invalid'));
        $this->assertSame('circle', CategoryVisualOptions::icon(null));
        $this->assertContains('gift', CategoryVisualOptions::icons());
    }
}
