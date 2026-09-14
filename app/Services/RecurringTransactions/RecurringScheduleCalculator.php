<?php

declare(strict_types=1);

namespace App\Services\RecurringTransactions;

use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Models\RecurringTransaction;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Calendar math for weekly, monthly, and yearly recurrence rules.
 *
 * Monthly rules keep the start-date ordinal day and fall back to the month end
 * when that ordinal is absent. Yearly rules use 29 February and fall back to 28
 * February in non-leap years. Every schedule date is bounded by the rule's
 * eligibility start (the later of start date and creation date) and its
 * inclusive end date.
 */
final class RecurringScheduleCalculator
{
    private const int SEARCH_LIMIT = 600;

    /** Earliest eligible schedule date on or after the requested date, or null when the rule can no longer occur. */
    public function firstEligibleOnOrAfter(RecurringTransaction $rule, string $date): ?string
    {
        $from = max($date, $rule->eligibility_starts_on->toDateString());

        return $this->candidateOnOrAfter($rule, $from);
    }

    /** @return list<string> */
    public function datesBetween(RecurringTransaction $rule, string $from, string $to): array
    {
        $end = min($to, $rule->endDateOrNull() ?? $to);
        if ($from > $end) {
            return [];
        }

        $dates = [];
        $candidate = $this->firstEligibleOnOrAfter($rule, $from);
        while ($candidate !== null && $candidate <= $end) {
            $dates[] = $candidate;
            $candidate = $this->advance($rule, $candidate);
        }

        return $dates;
    }

    /**
     * Next date the rule is expected to generate, skipping schedule dates that
     * already produced an occurrence.
     *
     * @param  array<string, bool>  $generatedDates  generated schedule dates keyed by date
     */
    public function nextExpectedOccurrence(RecurringTransaction $rule, string $businessDate, array $generatedDates = []): ?string
    {
        if (! $rule->isActive()) {
            return null;
        }

        $candidate = $this->firstEligibleOnOrAfter($rule, $businessDate);
        $guard = 0;
        while ($candidate !== null && isset($generatedDates[$candidate]) && $guard < self::SEARCH_LIMIT) {
            $candidate = $this->advance($rule, $candidate);
            $guard++;
        }

        return $candidate;
    }

    public function advance(RecurringTransaction $rule, string $date): ?string
    {
        return $this->candidateOnOrAfter(
            $rule,
            CarbonImmutable::parse($date)->addDay()->toDateString(),
        );
    }

    private function candidateOnOrAfter(RecurringTransaction $rule, string $from): ?string
    {
        $anchor = $rule->start_date->toDateString();
        $candidate = match ($rule->frequency) {
            RecurrenceFrequency::Weekly => $this->weeklyCandidate($anchor, $from),
            RecurrenceFrequency::Monthly => $this->monthlyCandidate($anchor, $from),
            RecurrenceFrequency::Yearly => $this->yearlyCandidate($anchor, $from),
        };
        $end = $rule->endDateOrNull();

        return $end !== null && $candidate > $end ? null : $candidate;
    }

    private function weeklyCandidate(string $anchor, string $from): string
    {
        $cursor = CarbonImmutable::parse($from);
        $weekday = CarbonImmutable::parse($anchor)->dayOfWeek;
        while ($cursor->dayOfWeek !== $weekday) {
            $cursor = $cursor->addDay();
        }

        return $cursor->toDateString();
    }

    private function monthlyCandidate(string $anchor, string $from): string
    {
        $anchorDay = CarbonImmutable::parse($anchor)->day;
        $cursor = CarbonImmutable::parse($from)->startOfMonth();

        for ($attempt = 0; $attempt < self::SEARCH_LIMIT; $attempt++) {
            $candidate = $cursor->day(min($anchorDay, $cursor->daysInMonth));
            if ($candidate->toDateString() >= $from) {
                return $candidate->toDateString();
            }
            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        throw new LogicException('Monthly recurrence candidate could not be resolved.');
    }

    private function yearlyCandidate(string $anchor, string $from): string
    {
        $anchorDate = CarbonImmutable::parse($anchor);
        $year = CarbonImmutable::parse($from)->year;

        for ($attempt = 0; $attempt < self::SEARCH_LIMIT; $attempt++) {
            $month = $anchorDate->month;
            $day = min($anchorDate->day, CarbonImmutable::create($year + $attempt, $month, 1)->daysInMonth);
            $candidate = CarbonImmutable::create($year + $attempt, $month, $day)->startOfDay()->toDateString();
            if ($candidate >= $from) {
                return $candidate;
            }
        }

        throw new LogicException('Yearly recurrence candidate could not be resolved.');
    }
}
