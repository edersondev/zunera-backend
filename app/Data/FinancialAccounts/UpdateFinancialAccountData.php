<?php

declare(strict_types=1);

namespace App\Data\FinancialAccounts;

use App\Enums\FinancialAccounts\AccountType;

final readonly class UpdateFinancialAccountData
{
    /**
     * @param array{
     *     name?: string,
     *     account_type?: AccountType,
     *     institution_name?: string|null,
     *     color?: string|null,
     *     icon?: string|null,
     *     initial_balance_centavos?: int,
     * } $changes
     */
    public function __construct(public array $changes) {}

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->changes);
    }
}
