<?php

declare(strict_types=1);

namespace App\Exceptions\CreditCards;

use RuntimeException;

final class CreditCardStateException extends RuntimeException
{
    private function __construct(
        private readonly string $stateCode,
        string $message,
        private readonly ?int $resultingAvailableCreditCentavos = null,
    ) {
        parent::__construct($message);
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('idempotency_key_reused', 'Idempotency-Key was already used for a different request.');
    }

    public static function cardArchived(): self
    {
        return new self('card_archived', 'Archived cards cannot accept new activity.');
    }

    public static function cardAlreadyActive(): self
    {
        return new self('card_already_active', 'This credit card is already active.');
    }

    public static function archiveBlockedByOutstanding(int $outstandingCentavos): self
    {
        return new self(
            'archive_blocked_by_outstanding',
            sprintf('Settle the outstanding amount of %s before archiving this card.', self::brl($outstandingCentavos)),
        );
    }

    public static function archiveBlockedByCredit(int $creditCentavos): self
    {
        return new self(
            'archive_blocked_by_card_credit',
            sprintf('Resolve the %s card credit before archiving this card.', self::brl($creditCentavos)),
        );
    }

    public static function statementNotPayable(): self
    {
        return new self('statement_not_payable', 'Only closed, partially paid, or overdue statements can receive payments.');
    }

    public static function statementAlreadySettled(): self
    {
        return new self('statement_already_settled', 'This statement has no outstanding amount to settle.');
    }

    public static function paymentExceedsOutstanding(int $outstandingCentavos): self
    {
        return new self(
            'payment_exceeds_outstanding',
            sprintf('Payment cannot exceed the outstanding amount of %s.', self::brl($outstandingCentavos)),
        );
    }

    public static function paymentAlreadyRemoved(): self
    {
        return new self('payment_already_removed', 'This payment is already removed.');
    }

    public static function paymentAlreadyActive(): self
    {
        return new self('payment_already_active', 'This payment is already active.');
    }

    public static function paymentEditRequiresRestore(): self
    {
        return new self('payment_edit_requires_restore', 'Restore the payment before editing it.');
    }

    public static function paymentNotEffective(): self
    {
        return new self('payment_not_effective', 'Only financially effective payments can be removed or changed.');
    }

    public static function purchaseNotDirectlyEditable(): self
    {
        return new self('purchase_not_directly_editable', 'A purchase can be corrected directly only while every installment is still open.');
    }

    public static function creditEventExceedsUncredited(int $uncreditedCentavos): self
    {
        return new self(
            'credit_event_exceeds_uncredited',
            sprintf('Credit event cannot exceed the uncredited purchase amount of %s.', self::brl($uncreditedCentavos)),
        );
    }

    public static function overLimitConfirmationRequired(int $resultingAvailableCreditCentavos): self
    {
        return new self(
            'OVER_LIMIT_CONFIRMATION_REQUIRED',
            sprintf('Confirm this purchase to continue over the card limit by %s.', self::brl(abs($resultingAvailableCreditCentavos))),
            $resultingAvailableCreditCentavos,
        );
    }

    public static function staleOverLimitConfirmation(): self
    {
        return new self('stale_over_limit_confirmation', 'The confirmed available credit no longer matches the card. Review the purchase again.');
    }

    public function errorCode(): string
    {
        return $this->stateCode;
    }

    public function isOverLimitConfirmationRequired(): bool
    {
        return $this->stateCode === 'OVER_LIMIT_CONFIRMATION_REQUIRED';
    }

    public function resultingAvailableCreditCentavos(): ?int
    {
        return $this->resultingAvailableCreditCentavos;
    }

    private static function brl(int $centavos): string
    {
        return 'R$ '.number_format($centavos / 100, 2, ',', '.');
    }
}
