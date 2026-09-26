<?php

declare(strict_types=1);

namespace App\Data\FinancialGoals;

final readonly class GoalInput
{
    public const MAX_CENTAVOS = 999_999_999_999;

    public function __construct(
        public string $name,
        public int $targetCentavos,
        public ?string $targetDate,
        public ?int $financialAccountId,
        public ?string $description,
        public int $initialAllocatedCentavos,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromValidated(array $data): self
    {
        return new self(
            name: trim($data['name']),
            targetCentavos: self::centavos($data['target_centavos']),
            targetDate: $data['target_date'] ?? null,
            financialAccountId: isset($data['financial_account_id']) ? (int) $data['financial_account_id'] : null,
            description: $data['description'] ?? null,
            initialAllocatedCentavos: self::centavos($data['initial_allocated_centavos'] ?? 0),
        );
    }

    public static function centavos(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > self::MAX_CENTAVOS) {
            throw new \InvalidArgumentException('Expected integer BRL centavos in supported range.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function fingerprintPayload(): array
    {
        return [
            'name' => $this->name,
            'target_centavos' => $this->targetCentavos,
            'target_date' => $this->targetDate,
            'financial_account_id' => $this->financialAccountId,
            'description' => $this->description,
            'initial_allocated_centavos' => $this->initialAllocatedCentavos,
        ];
    }
}
