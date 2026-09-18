<?php

declare(strict_types=1);

namespace App\Data\Budgets;

use Carbon\CarbonImmutable;

/**
 * One owner-scoped calendar month. Calendar boundaries always resolve in the
 * Brazilian business timezone; the month itself never carries a period mode.
 */
final readonly class BudgetMonthData
{
    public const string BUSINESS_TIMEZONE = 'America/Sao_Paulo';

    public function __construct(
        public int $userId,
        public int $year,
        public int $month,
    ) {}

    public static function businessDate(): CarbonImmutable
    {
        return CarbonImmutable::now(self::BUSINESS_TIMEZONE);
    }
}
