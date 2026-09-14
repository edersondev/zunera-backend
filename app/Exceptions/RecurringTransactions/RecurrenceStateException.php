<?php

declare(strict_types=1);

namespace App\Exceptions\RecurringTransactions;

use RuntimeException;

final class RecurrenceStateException extends RuntimeException
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

    public static function notActive(): self
    {
        return new self('recurrence_not_active', 'This action requires an active recurring transaction.');
    }

    public static function notPaused(): self
    {
        return new self('recurrence_not_paused', 'This action requires a paused recurring transaction.');
    }

    public static function ended(): self
    {
        return new self('recurrence_ended', 'Ended recurring transactions cannot be changed.');
    }

    public static function associationUnavailable(string $field = 'financial_account_id'): self
    {
        return new self(
            'recurrence_association_unavailable',
            'Provide an eligible active account and matching category before resuming.',
            errors: [$field => ['Provide an eligible active account and matching category before resuming.']],
        );
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('idempotency_key_reused', 'Idempotency-Key was already used for a different request.');
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
