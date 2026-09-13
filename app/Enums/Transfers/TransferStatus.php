<?php

declare(strict_types=1);

namespace App\Enums\Transfers;

enum TransferStatus: string
{
    case Pending = 'pending';
    case Effective = 'effective';

    public function isEffective(): bool
    {
        return $this === self::Effective;
    }
}
