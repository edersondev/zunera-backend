<?php

declare(strict_types=1);

namespace App\Data\CreditCards;

final readonly class CreateCreditCardData
{
    public function __construct(
        public int $userId,
        public string $name,
        public string $institutionName,
        public ?string $lastFour,
        public ?string $color,
        public ?string $icon,
        public int $creditLimitCentavos,
        public int $closingDay,
        public int $dueDay,
    ) {}
}
