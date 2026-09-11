<?php

declare(strict_types=1);

namespace App\Data\Transactions;

use App\Enums\Transactions\TransactionStatus;
use App\Enums\Transactions\TransactionType;

final readonly class CreateTransactionData
{
    public function __construct(
        public int $userId,
        public int $financialAccountId,
        public int $categoryId,
        public TransactionType $type,
        public string $description,
        public ?string $notes,
        public int $amountCentavos,
        public string $transactionDate,
        public ?TransactionStatus $status,
    ) {}
}
