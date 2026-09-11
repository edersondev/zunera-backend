<?php

declare(strict_types=1);

namespace App\Exceptions\Transactions;

use RuntimeException;

final class TransactionStateException extends RuntimeException
{
    private function __construct(private readonly string $stateCode, string $message)
    {
        parent::__construct($message);
    }

    public static function alreadyRemoved(): self
    {
        return new self('transaction_already_removed', 'Transaction is already removed.');
    }

    public static function alreadyActive(): self
    {
        return new self('transaction_already_active', 'Transaction is already active.');
    }

    public static function editRequiresRestore(): self
    {
        return new self('transaction_edit_requires_restore', 'Restore the transaction before editing it.');
    }

    public static function stateNotAllowed(): self
    {
        return new self('transaction_state_not_allowed', 'This transaction state is not allowed.');
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('idempotency_key_reused', 'Idempotency-Key was already used for a different request.');
    }

    public function errorCode(): string
    {
        return $this->stateCode;
    }
}
