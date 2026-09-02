<?php

declare(strict_types=1);

namespace App\Enums\FinancialAccounts;

enum AccountStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isArchived(): bool
    {
        return $this === self::Archived;
    }
}
