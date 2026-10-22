<?php

declare(strict_types=1);

namespace App\Data\CreditCards;

final readonly class MovePurchaseData
{
    public function __construct(
        public string $mode,
        public string $targetClosingDate,
        public ?int $installmentId,
        public ?string $previewToken,
    ) {}

    /** @return array{mode: string, target_closing_date: string, installment_id: ?int} */
    public function choice(): array
    {
        return [
            'mode' => $this->mode,
            'target_closing_date' => $this->targetClosingDate,
            'installment_id' => $this->installmentId,
        ];
    }
}
