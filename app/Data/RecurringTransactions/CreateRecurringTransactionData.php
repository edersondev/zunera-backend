<?php

declare(strict_types=1);

namespace App\Data\RecurringTransactions;

use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\Transactions\TransactionType;

final readonly class CreateRecurringTransactionData
{
    public function __construct(
        public int $userId,
        public int $financialAccountId,
        public int $categoryId,
        public TransactionType $type,
        public int $amountCentavos,
        public string $description,
        public ?string $notes,
        public RecurrenceFrequency $frequency,
        public string $startDate,
        public ?string $endDate,
    ) {}
}
