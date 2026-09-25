<?php

declare(strict_types=1);

namespace App\Enums\RecurringTransactions;

enum CardGenerationMode: string
{
    case Automatic = 'automatic';
    case Confirmation = 'confirmation';
}
