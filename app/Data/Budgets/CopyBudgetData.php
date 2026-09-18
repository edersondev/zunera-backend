<?php

declare(strict_types=1);

namespace App\Data\Budgets;

final readonly class CopyBudgetData
{
    public function __construct(
        public int $userId,
        public int $sourceBudgetId,
        public int $destinationYear,
        public int $destinationMonth,
    ) {}
}
