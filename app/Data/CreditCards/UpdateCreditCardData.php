<?php

declare(strict_types=1);

namespace App\Data\CreditCards;

final readonly class UpdateCreditCardData
{
    /** @param array<string, mixed> $changes */
    public function __construct(public array $changes) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->changes);
    }

    /** @return array<string, mixed> */
    public function serializable(): array
    {
        return $this->changes;
    }
}
