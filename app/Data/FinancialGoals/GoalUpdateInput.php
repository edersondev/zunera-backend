<?php

declare(strict_types=1);

namespace App\Data\FinancialGoals;

final readonly class GoalUpdateInput
{
    /** @param array<string, mixed> $changes */
    public function __construct(public array $changes) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        unset($validated['idempotency_key']);
        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim($validated['name']);
        }
        if (array_key_exists('target_centavos', $validated)) {
            $validated['target_centavos'] = GoalInput::centavos($validated['target_centavos']);
        }
        ksort($validated);

        return new self($validated);
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->changes);
    }

    public function get(string $field): mixed
    {
        return $this->changes[$field] ?? null;
    }
}
