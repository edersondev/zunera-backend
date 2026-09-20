<?php

declare(strict_types=1);

namespace App\Data\CreditCards;

use App\Enums\CreditCards\CreditCardCreditEventReason;

final readonly class CreateCreditEventData
{
    public function __construct(
        public int $userId,
        public CreditCardCreditEventReason $reason,
        public int $amountCentavos,
        public string $eventDate,
        public ?string $notes,
    ) {}
}
