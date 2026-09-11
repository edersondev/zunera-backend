<?php

declare(strict_types=1);

namespace App\Data\Transactions;

use App\Enums\Transactions\TransactionStatus;
use App\Enums\Transactions\TransactionType;

final readonly class TransactionFilterData
{
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?TransactionType $type = null,
        public ?int $financialAccountId = null,
        public ?int $categoryId = null,
        public ?TransactionStatus $status = null,
        public ?string $search = null,
        public string $view = 'active',
        public int $page = 1,
        public int $perPage = 50,
    ) {}
}
