<?php

declare(strict_types=1);

namespace App\Exceptions\FinancialAccounts;

use RuntimeException;

final class FinancialAccountNameConflictException extends RuntimeException
{
    public static function activeNameConflict(): self
    {
        return new self('An active account with this name already exists.', 409);
    }

    public function errorCode(): string
    {
        return 'account_name_conflict';
    }
}
