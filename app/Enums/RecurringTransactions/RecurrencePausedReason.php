<?php

declare(strict_types=1);

namespace App\Enums\RecurringTransactions;

enum RecurrencePausedReason: string
{
    case User = 'user';
    case AssociationArchived = 'association_archived';
}
