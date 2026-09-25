<?php

declare(strict_types=1);

namespace App\Data\RecurringTransactions;

use App\Enums\RecurringTransactions\RecurrenceDestinationType;
use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionType;

final readonly class RecurringTransactionFilterData
{
    public function __construct(
        public ?TransactionType $type = null,
        public ?RecurrenceDestinationType $destinationType = null,
        public ?int $financialAccountId = null,
        public ?int $creditCardId = null,
        public ?int $categoryId = null,
        public ?RecurrenceFrequency $frequency = null,
        public ?RecurrenceState $state = null,
        public int $page = 1,
        public int $perPage = 50,
    ) {}
}
