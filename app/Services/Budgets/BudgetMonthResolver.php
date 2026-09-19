<?php

declare(strict_types=1);

namespace App\Services\Budgets;

use App\Data\Budgets\BudgetMonthData;
use Carbon\CarbonImmutable;

/**
 * Owns budget month boundaries. A budget always covers the full first-to-last
 * calendar month in the Brazilian business timezone, never the dashboard's
 * current-month-through-today period.
 */
final class BudgetMonthResolver
{
    public function start(int $year, int $month): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, 1, 0, 0, 0, BudgetMonthData::BUSINESS_TIMEZONE)->startOfMonth();
    }

    public function end(int $year, int $month): CarbonImmutable
    {
        return $this->start($year, $month)->endOfMonth();
    }

    public function from(int $year, int $month): string
    {
        return $this->start($year, $month)->toDateString();
    }

    public function to(int $year, int $month): string
    {
        return $this->end($year, $month)->toDateString();
    }

    /** @return array{year: int, month: int, from: string, to: string} */
    public function period(int $year, int $month): array
    {
        return ['year' => $year, 'month' => $month, 'from' => $this->from($year, $month), 'to' => $this->to($year, $month)];
    }

    public function isEnded(int $year, int $month, ?CarbonImmutable $businessDate = null): bool
    {
        return ($businessDate ?? BudgetMonthData::businessDate())->greaterThan($this->end($year, $month));
    }
}
