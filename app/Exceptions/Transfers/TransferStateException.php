<?php

declare(strict_types=1);

namespace App\Exceptions\Transfers;

use RuntimeException;

final class TransferStateException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    private function __construct(
        private readonly string $stateCode,
        string $message,
        private readonly int $statusCode = 409,
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public static function alreadyRemoved(): self
    {
        return new self('transfer_already_removed', 'Transfer is already removed.');
    }

    public static function alreadyActive(): self
    {
        return new self('transfer_already_active', 'Transfer is already active.');
    }

    public static function editRequiresRestore(): self
    {
        return new self('transfer_edit_requires_restore', 'Restore the transfer before editing it.');
    }

    public static function insufficientSourceBalance(): self
    {
        return new self('insufficient_source_balance', 'The source account does not have enough balance for this effective transfer.');
    }

    public static function accountBalanceOutOfRange(): self
    {
        return new self('account_balance_out_of_range', 'The resulting account balance is outside the supported range.');
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('idempotency_key_reused', 'Idempotency-Key was already used for a different request.');
    }

    public static function sameSide(string $field = 'destination_financial_account_id'): self
    {
        return new self(
            'transfer_sides_must_differ',
            'Source and destination accounts must differ.',
            statusCode: 422,
            errors: [$field => ['Source and destination accounts must differ.']],
        );
    }

    public static function effectiveFutureDate(string $field = 'status'): self
    {
        return new self(
            'effective_future_date',
            'Future-dated transfers must remain pending until the transfer date is today or in the past.',
            statusCode: 422,
            errors: [$field => ['Future-dated transfers must remain pending.']],
        );
    }

    public function errorCode(): string
    {
        return $this->stateCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
