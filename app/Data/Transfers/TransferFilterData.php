<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Enums\Transfers\TransferStatus;

final readonly class TransferFilterData
{
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?int $sourceFinancialAccountId = null,
        public ?int $destinationFinancialAccountId = null,
        public ?TransferStatus $status = null,
        public ?string $search = null,
        public string $view = 'active',
        public int $page = 1,
        public int $perPage = 50,
    ) {}
}
