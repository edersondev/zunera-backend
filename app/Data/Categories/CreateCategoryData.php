<?php

namespace App\Data\Categories;

use App\Enums\Categories\CategoryClassification;

final readonly class CreateCategoryData
{
    public function __construct(
        public int $userId,
        public string $name,
        public CategoryClassification $classification,
        public ?string $color = null,
        public ?string $icon = null,
    ) {}
}
