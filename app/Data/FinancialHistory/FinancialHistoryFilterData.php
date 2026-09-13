<?php

declare(strict_types=1);

namespace App\Data\FinancialHistory;

use App\Enums\Transactions\TransactionStatus;

final readonly class FinancialHistoryFilterData
{
    public function __construct(
        public string $movementKind = 'all',
        public ?string $from = null,
        public ?string $to = null,
        public ?int $financialAccountId = null,
        public ?TransactionStatus $status = null,
        public ?string $search = null,
        public int $page = 1,
        public int $perPage = 50,
    ) {}

    public function includesTransactions(): bool
    {
        return $this->movementKind !== 'transfer';
    }

    public function includesTransfers(): bool
    {
        return $this->movementKind !== 'income' && $this->movementKind !== 'expense';
    }

    public function transactionType(): ?string
    {
        return in_array($this->movementKind, ['income', 'expense'], true) ? $this->movementKind : null;
    }
}
