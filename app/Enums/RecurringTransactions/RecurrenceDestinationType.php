<?php

declare(strict_types=1);

namespace App\Enums\RecurringTransactions;

enum RecurrenceDestinationType: string
{
    case FinancialAccount = 'financial_account';
    case CreditCard = 'credit_card';

    public function isCard(): bool
    {
        return $this === self::CreditCard;
    }

    public function isAccount(): bool
    {
        return $this === self::FinancialAccount;
    }
}
