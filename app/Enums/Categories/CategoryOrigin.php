<?php

namespace App\Enums\Categories;

enum CategoryOrigin: string
{
    case System = 'system';
    case Personal = 'personal';

    public function isSystem(): bool
    {
        return $this === self::System;
    }

    public function isPersonal(): bool
    {
        return $this === self::Personal;
    }
}
