<?php

namespace App\Data\Categories;

use App\Enums\Categories\CategoryClassification;

final readonly class UpdateCategoryData
{
    /** @param array{name?: string, classification?: CategoryClassification, color?: string|null, icon?: string|null} $changes */
    public function __construct(public array $changes) {}

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->changes);
    }
}
