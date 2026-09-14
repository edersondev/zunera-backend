<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Enums\Transfers\TransferStatus;

final readonly class CreateTransferData
{
    public function __construct(
        public int $userId,
        public int $sourceFinancialAccountId,
        public int $destinationFinancialAccountId,
        public int $amountCentavos,
        public string $transferDate,
        public ?TransferStatus $status,
        public ?string $description,
        public ?string $notes,
    ) {}
}
