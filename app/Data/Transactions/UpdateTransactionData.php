<?php

declare(strict_types=1);

namespace App\Data\Transactions;

final readonly class UpdateTransactionData
{
    /** @param array<string, mixed> $changes */
    public function __construct(public array $changes) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->changes);
    }
}
