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

    public static function dueProcessingIncomplete(): self
    {
        return new self('recurrence_due_processing_incomplete', 'Some already due dates could not be processed. Review the occurrences and try again.');
    }

    public static function occurrenceAlreadyRecorded(): self
    {
        return new self('occurrence_already_recorded', 'This occurrence already recorded its purchase.');
    }

    public static function occurrenceDismissed(): self
    {
        return new self('occurrence_dismissed', 'A dismissed occurrence cannot be changed.');
    }

    public static function occurrenceNotActionable(): self
    {
        return new self('occurrence_not_actionable', 'This occurrence cannot perform the requested action in its current state.');
    }

    public static function occurrenceActionInProgress(): self
    {
        return new self('occurrence_action_in_progress', 'Another confirmation attempt is already in progress for this occurrence.');
    }

    public static function staleOverLimitConfirmation(): self
    {
        return new self('stale_over_limit_confirmation', 'The confirmed available credit no longer matches the card. Review the occurrence again.');
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
