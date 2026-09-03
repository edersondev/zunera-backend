<?php

declare(strict_types=1);

namespace App\Data\FinancialAccounts;

use App\Enums\FinancialAccounts\AccountType;

final readonly class CreateFinancialAccountData
{
    public function __construct(
        public int $userId,
        public string $name,
        public AccountType $accountType,
        public int $initialBalanceCentavos,
        public ?string $institutionName = null,
        public ?string $color = null,
        public ?string $icon = null,
    ) {}
}
