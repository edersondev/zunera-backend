<?php

declare(strict_types=1);

namespace App\Enums\FinancialAccounts;

enum AccountType: string
{
    case Checking = 'checking';
    case Savings = 'savings';
    case CashWallet = 'cash_wallet';
    case Investment = 'investment';
    case Digital = 'digital';
    case Other = 'other';
}
