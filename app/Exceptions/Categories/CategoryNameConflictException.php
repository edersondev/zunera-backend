<?php

namespace App\Exceptions\Categories;

use RuntimeException;

final class CategoryNameConflictException extends RuntimeException
{
    public static function activeNameConflict(): self
    {
        return new self('An active category with this name and classification already exists.', 409);
    }

    public static function systemDefaultConflict(): self
    {
        return new self('A system default category already uses this name and classification.', 409);
    }

    public function errorCode(): string
    {
        return $this->getMessage() === 'A system default category already uses this name and classification.' ? 'category_system_default_conflict' : 'category_name_conflict';
    }
}
