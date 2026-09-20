<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Data\CreditCards\BillingCycleData;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Calendar-aware billing cycles in the America/Sao_Paulo business timezone.
 *
 * The closing day is inclusive: a purchase made on the closing date belongs to
 * the statement closing that day, and the statement stays open through that
 * whole calendar day. Missing month days clamp to the final day of the month,
 * and the due date is the first configured due-day occurrence strictly after
 * the actual closing date.
 */
final class BillingCycleCalculator
{
    public function businessToday(): CarbonImmutable
    {
        return CarbonImmutable::now(CreditCardMoney::BUSINESS_TIME_ZONE)->startOfDay();
    }

    public function cycleForDate(DateTimeInterface|string $date, int $closingDay, int $dueDay): BillingCycleData
    {
        $reference = $this->toImmutable($date);
        $reference = $reference->startOfDay();

        $closing = $this->clampToMonth($reference->year, $reference->month, $closingDay);
        if ($closing->lessThan($reference)) {
            $next = $reference->addMonthNoOverflow()->startOfMonth();
            $closing = $this->clampToMonth($next->year, $next->month, $closingDay);
        }

        return $this->cycleForClosing($closing, $closingDay, $dueDay);
    }

    public function nextCycle(BillingCycleData $cycle, int $closingDay, int $dueDay): BillingCycleData
    {
        $closing = $this->toImmutable($cycle->closingDate);
        $next = $this->clampToMonth($closing->year, $closing->month, $closingDay)->addMonthNoOverflow()->startOfMonth();

        return $this->cycleForClosing($this->clampToMonth($next->year, $next->month, $closingDay), $closingDay, $dueDay);
    }

    public function cycleForClosing(DateTimeInterface|string $closingDate, int $closingDay, int $dueDay): BillingCycleData
    {
        $closing = $this->toImmutable($closingDate);
        $previousMonth = $closing->subMonthNoOverflow()->startOfMonth();
        $previousClosing = $this->clampToMonth($previousMonth->year, $previousMonth->month, $closingDay);

        $dueCandidate = $this->clampToMonth($closing->year, $closing->month, $dueDay);
        if ($dueCandidate->lessThanOrEqualTo($closing)) {
            $nextMonth = $closing->addMonthNoOverflow()->startOfMonth();
            $dueCandidate = $this->clampToMonth($nextMonth->year, $nextMonth->month, $dueDay);
        }

        return new BillingCycleData(
            periodFrom: $previousClosing->addDay()->toDateString(),
            periodTo: $closing->toDateString(),
            closingDate: $closing->toDateString(),
            dueDate: $dueCandidate->toDateString(),
        );
    }

    /** A statement stays open through its inclusive closing calendar day. */
    public function isOpenOn(DateTimeInterface|string $businessDate, DateTimeInterface|string $closingDate): bool
    {
        // Calendar dates are compared as date strings so a stored UTC date and
        // the America/Sao_Paulo business clock can never disagree by a day.
        return $this->toImmutable($businessDate)->toDateString() <= $this->toImmutable($closingDate)->toDateString();
    }

    private function toImmutable(DateTimeInterface|string $date): CarbonImmutable
    {
        return $date instanceof CarbonImmutable
            ? $date
            : CarbonImmutable::parse($date, CreditCardMoney::BUSINESS_TIME_ZONE);
    }

    private function clampToMonth(int $year, int $month, int $day): CarbonImmutable
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, CreditCardMoney::BUSINESS_TIME_ZONE);
        $clampedDay = min($day, $start->daysInMonth);

        return $start->day($clampedDay);
    }
}
