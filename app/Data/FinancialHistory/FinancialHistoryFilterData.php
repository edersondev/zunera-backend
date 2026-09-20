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
        public ?int $categoryId = null,
        public string $view = 'active',
        public ?string $include = null,
        public int $page = 1,
        public int $perPage = 50,
    ) {}

    public function includesTransactions(): bool
    {
        return in_array($this->movementKind, ['all', 'income', 'expense'], true);
    }

    public function includesTransfers(): bool
    {
        return $this->categoryId === null
            && $this->movementKind !== 'income'
            && $this->movementKind !== 'expense'
            && $this->movementKind !== 'credit_card_expense';
    }

    /**
     * Recognized card installments are discriminated history entries; statement
     * payments are settlement only and never appear as history entries.
     */
    public function includesCreditCardExpenses(): bool
    {
        return in_array($this->movementKind, ['all', 'credit_card_expense'], true);
    }

    public function includesRecurring(): bool
    {
        return $this->include === 'recurring'
            && $this->view === 'active'
            && $this->movementKind !== 'transfer'
            && $this->status === null;
    }

    public function transactionType(): ?string
    {
        return in_array($this->movementKind, ['income', 'expense'], true) ? $this->movementKind : null;
    }
}
