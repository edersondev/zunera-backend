<?php

declare(strict_types=1);

namespace App\Services\FinancialReports;

use App\Data\FinancialReports\ReportScope;
use App\Services\Transactions\TransactionDateRange;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Resolves one selected calendar scope and its explicitly labelled prior range. */
final class ReportPeriodResolver
{
    public const string BUSINESS_TIMEZONE = 'America/Sao_Paulo';

    /** @param array<string, mixed> $parameters */
    public function resolve(int $userId, array $parameters, ?CarbonImmutable $businessDate = null): ReportScope
    {
        $today = ($businessDate ?? CarbonImmutable::now(self::BUSINESS_TIMEZONE))->setTimezone(self::BUSINESS_TIMEZONE)->startOfDay();
        $preset = (string) ($parameters['preset'] ?? 'current_month');
        $month = $parameters['month'] ?? null;
        $fromInput = $parameters['from'] ?? null;
        $toInput = $parameters['to'] ?? null;

        if ($preset === 'historical_month') {
            if (! is_string($month) || ! preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $month) || $fromInput !== null || $toInput !== null) {
                throw new InvalidArgumentException('Historical month requires one YYYY-MM month and no custom dates.');
            }
            $current = $this->date($month.'-01');
            if ($current >= $today->startOfMonth()) {
                throw new InvalidArgumentException('Historical month must be completed before the current business month.');
            }
            $from = $current->startOfMonth();
            $to = $current->endOfMonth();
            $priorFrom = $current->subMonthNoOverflow()->startOfMonth();
            $priorTo = $priorFrom->endOfMonth();
        } elseif ($preset === 'custom') {
            if ($month !== null || ! is_string($fromInput) || ! is_string($toInput)) {
                throw new InvalidArgumentException('Custom range requires both dates and no historical month.');
            }
            $from = $this->date($fromInput);
            $to = $this->date($toInput);
            if ($from > $to) {
                throw new InvalidArgumentException('Custom range end must be on or after its start.');
            }
            $dayCount = (int) $from->diffInDays($to) + 1;
            $priorTo = $from->subDay();
            $priorFrom = $priorTo->subDays($dayCount - 1);
        } else {
            if ($month !== null || $fromInput !== null || $toInput !== null) {
                throw new InvalidArgumentException('Calendar presets cannot include month or custom dates.');
            }

            [$from, $to, $priorFrom, $priorTo] = match ($preset) {
                'current_month' => $this->currentMonth($today),
                'previous_month' => $this->completedMonth($today->subMonthNoOverflow()),
                'current_year' => $this->currentYear($today),
                'previous_year' => $this->completedYear($today->subYearNoOverflow()),
                default => throw new InvalidArgumentException('Unsupported report period preset.'),
            };
        }

        if ($priorFrom->toDateString() < TransactionDateRange::MIN_DATE || $to->toDateString() > TransactionDateRange::MAX_DATE) {
            throw new InvalidArgumentException('Report period falls outside supported financial dates.');
        }

        return new ReportScope(
            userId: $userId,
            preset: $preset,
            month: $preset === 'historical_month' ? $month : null,
            currentFrom: $from->toDateString(),
            currentTo: $to->toDateString(),
            previousFrom: $priorFrom->toDateString(),
            previousTo: $priorTo->toDateString(),
            accountId: isset($parameters['account_id']) ? (int) $parameters['account_id'] : null,
            categoryId: isset($parameters['category_id']) ? (int) $parameters['category_id'] : null,
            transactionType: $parameters['transaction_type'] ?? null,
        );
    }

    public function percentUnavailableReason(ReportScope $scope, int $current, int $previous): ?string
    {
        if ($scope->currentDayCount() !== $scope->previousDayCount()) {
            return 'unequal_duration';
        }
        if ($previous <= 0) {
            return 'previous_nonpositive';
        }
        if ($current < 0) {
            return 'sign_crossing';
        }

        return null;
    }

    /** @return array{CarbonImmutable, CarbonImmutable, CarbonImmutable, CarbonImmutable} */
    private function currentMonth(CarbonImmutable $today): array
    {
        $priorFrom = $today->subMonthNoOverflow()->startOfMonth();
        $priorTo = $priorFrom->day(min($today->day, $priorFrom->daysInMonth));

        return [$today->startOfMonth(), $today, $priorFrom, $priorTo];
    }

    /** @return array{CarbonImmutable, CarbonImmutable, CarbonImmutable, CarbonImmutable} */
    private function completedMonth(CarbonImmutable $month): array
    {
        $priorFrom = $month->subMonthNoOverflow()->startOfMonth();

        return [$month->startOfMonth(), $month->endOfMonth(), $priorFrom, $priorFrom->endOfMonth()];
    }

    /** @return array{CarbonImmutable, CarbonImmutable, CarbonImmutable, CarbonImmutable} */
    private function currentYear(CarbonImmutable $today): array
    {
        $priorYear = $today->year - 1;
        $priorDay = min($today->day, CarbonImmutable::create($priorYear, $today->month, 1)->daysInMonth);
        $priorTo = CarbonImmutable::create($priorYear, $today->month, $priorDay, 0, 0, 0, self::BUSINESS_TIMEZONE);

        return [$today->startOfYear(), $today, $priorTo->startOfYear(), $priorTo];
    }

    /** @return array{CarbonImmutable, CarbonImmutable, CarbonImmutable, CarbonImmutable} */
    private function completedYear(CarbonImmutable $year): array
    {
        $prior = $year->subYearNoOverflow();

        return [$year->startOfYear(), $year->endOfYear(), $prior->startOfYear(), $prior->endOfYear()];
    }

    private function date(string $date): CarbonImmutable
    {
        if (! preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])$/', $date)) {
            throw new InvalidArgumentException('Report date must be a valid ISO calendar date.');
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::BUSINESS_TIMEZONE);
        if ($parsed === false || $parsed->toDateString() !== $date) {
            throw new InvalidArgumentException('Report date must be a valid ISO calendar date.');
        }

        return $parsed->startOfDay();
    }
}
