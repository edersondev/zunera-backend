<?php

declare(strict_types=1);

namespace App\Data\RecurringTransactions;

final readonly class ConfirmCardOccurrenceData
{
    public function __construct(
        public ?int $actualAmountCentavos = null,
        public ?string $actualPurchaseDate = null,
        public ?int $creditCardId = null,
        public ?int $categoryId = null,
        public bool $confirmOverLimit = false,
        public ?int $expectedAvailableCreditCentavos = null,
    ) {}
}
