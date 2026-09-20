<?php

declare(strict_types=1);

namespace App\Data\CreditCards;

final readonly class CreatePurchaseData
{
    public function __construct(
        public int $userId,
        public int $creditCardId,
        public int $categoryId,
        public string $description,
        public ?string $notes,
        public int $totalAmountCentavos,
        public int $installmentCount,
        public string $purchaseDate,
        public bool $overLimitConfirmed,
        public ?int $confirmedAvailableCreditCentavos,
    ) {}
}
