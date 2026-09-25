<?php

declare(strict_types=1);

namespace App\Enums\RecurringTransactions;

enum CardOccurrenceState: string
{
    case Expected = 'expected';
    case AwaitingOverLimit = 'awaiting_over_limit';
    case Failed = 'failed';
    case Dismissed = 'dismissed';
    case Recorded = 'recorded';

    public function isActionable(): bool
    {
        return in_array($this, [self::Expected, self::AwaitingOverLimit, self::Failed], true);
    }

    public function isRecorded(): bool
    {
        return $this === self::Recorded;
    }

    public function isDismissed(): bool
    {
        return $this === self::Dismissed;
    }
}
