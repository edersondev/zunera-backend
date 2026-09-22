<?php

declare(strict_types=1);

namespace App\Data\CreditCards;

final readonly class BillingCycleData
{
    public function __construct(
        public string $periodFrom,
        public string $periodTo,
        public string $closingDate,
        public string $dueDate,
    ) {}

    /** @return array{period_from: string, period_to: string, closing_date: string, due_date: string} */
    public function toArray(): array
    {
        return [
            'period_from' => $this->periodFrom,
            'period_to' => $this->periodTo,
            'closing_date' => $this->closingDate,
            'due_date' => $this->dueDate,
        ];
    }
}
