<?php

declare(strict_types=1);

namespace App\Enums\RecurringTransactions;

enum RecurrenceState: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isPaused(): bool
    {
        return $this === self::Paused;
    }

    public function isEnded(): bool
    {
        return $this === self::Ended;
    }
}
