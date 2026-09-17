<?php

declare(strict_types=1);

namespace App\Enums\RecurringTransactions;

enum RecurrenceFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
