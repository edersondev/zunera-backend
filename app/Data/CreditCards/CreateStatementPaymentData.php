<?php

declare(strict_types=1);

namespace App\Data\CreditCards;

use App\Enums\CreditCards\CreditCardPaymentStatus;

final readonly class CreateStatementPaymentData
{
    public function __construct(
        public int $userId,
        public int $financialAccountId,
        public int $amountCentavos,
        public string $paymentDate,
        public ?string $notes,
        public ?CreditCardPaymentStatus $status = null,
    ) {}
}
