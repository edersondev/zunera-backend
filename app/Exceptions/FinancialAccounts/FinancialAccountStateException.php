<?php

declare(strict_types=1);

namespace App\Exceptions\FinancialAccounts;

use RuntimeException;

final class FinancialAccountStateException extends RuntimeException
{
    public static function alreadyArchived(): self
    {
        return new self('This account is already archived.', 409);
    }

    public static function alreadyActive(): self
    {
        return new self('This account is already active.', 409);
    }

    public static function initialBalanceLocked(): self
    {
        return new self('The initial balance can no longer be changed because this account has financial movements.', 409);
    }

    public function errorCode(): string
    {
        return match (true) {
            $this->getMessage() === 'This account is already archived.' => 'account_already_archived',
            $this->getMessage() === 'This account is already active.' => 'account_already_active',
            default => 'initial_balance_locked',
        };
    }
}
