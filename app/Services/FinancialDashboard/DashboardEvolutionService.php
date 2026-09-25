<?php

declare(strict_types=1);

namespace App\Services\FinancialDashboard;

use App\Data\FinancialDashboard\DashboardPeriodData;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Realized income, expense, and result per reporting interval. Daily buckets
 * cover periods through 31 days, weekly through 93, and monthly beyond; the
 * first and last calendar buckets are marked partial when the period clips them.
 */
final class DashboardEvolutionService
{
    public function __construct(private readonly RecurringCardExpenseProjection $cardExpenses) {}

    /** @return array<string, mixed> */
    public function evolution(User $user, DashboardPeriodData $period): array
    {
        $totals = $this->totalsByDate($user, $period);

        $intervals = [];
        foreach ($this->buckets($period) as $bucket) {
            $income = 0;
            $expenses = 0;
            $cursor = CarbonImmutable::parse($bucket['from']);
            $end = CarbonImmutable::parse($bucket['to']);
            while ($cursor <= $end) {
                $totalsForDay = $totals[$cursor->toDateString()] ?? null;
                $income += $totalsForDay['income_centavos'] ?? 0;
                $expenses += $totalsForDay['expenses_centavos'] ?? 0;
                $cursor = $cursor->addDay();
            }

            $intervals[] = [
                'from' => $bucket['from'],
                'to' => $bucket['to'],
                'label' => $bucket['label'],
                'is_partial' => $bucket['is_partial'],
                'income_centavos' => $income,
                'expenses_centavos' => $expenses,
                'result_centavos' => $income - $expenses,
            ];
        }

        return [
            'period' => $period,
            'interval' => $period->interval(),
            'currency_code' => 'BRL',
            'intervals' => $intervals,
        ];
    }

    /**
     * @return array<string, array{income_centavos: int, expenses_centavos: int}>
     */
    private function totalsByDate(User $user, DashboardPeriodData $period): array
    {
        $rows = DB::table('transactions')
            ->where('user_id', $user->id)
            ->whereNull('removed_at')
            ->where('status', 'effective')
            ->whereDate('transaction_date', '>=', $period->from)
            ->whereDate('transaction_date', '<=', $period->to)
            ->groupBy('transaction_date')
            ->selectRaw('transaction_date as movement_date')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount_centavos ELSE 0 END), 0) as income_centavos")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount_centavos ELSE 0 END), 0) as expense_centavos")
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[substr((string) $row->movement_date, 0, 10)] = [
                'income_centavos' => (int) $row->income_centavos,
                'expenses_centavos' => (int) $row->expense_centavos,
            ];
        }

        foreach ($this->cardExpenses->byDate($user, $period->from, $period->to) as $row) {
            $date = substr((string) $row->movement_date, 0, 10);
            $totals[$date] ??= ['income_centavos' => 0, 'expenses_centavos' => 0];
            $totals[$date]['expenses_centavos'] += (int) $row->expenses_centavos;
        }

        return $totals;
    }

    /**
     * @return list<array{from: string, to: string, label: string, is_partial: bool}>
     */
    private function buckets(DashboardPeriodData $period): array
    {
        return match ($period->interval()) {
            'daily' => $this->dailyBuckets($period),
            'weekly' => $this->weeklyBuckets($period),
            default => $this->monthlyBuckets($period),
        };
    }

    /** @return list<array{from: string, to: string, label: string, is_partial: bool}> */
    private function dailyBuckets(DashboardPeriodData $period): array
    {
        $buckets = [];
        $cursor = CarbonImmutable::parse($period->from);
        $end = CarbonImmutable::parse($period->to);

        while ($cursor <= $end) {
            $buckets[] = [
                'from' => $cursor->toDateString(),
                'to' => $cursor->toDateString(),
                'label' => $cursor->format('d/m'),
                'is_partial' => false,
            ];
            $cursor = $cursor->addDay();
        }

        return $buckets;
    }

    /** @return list<array{from: string, to: string, label: string, is_partial: bool}> */
    private function weeklyBuckets(DashboardPeriodData $period): array
    {
        $buckets = [];
        $periodStart = CarbonImmutable::parse($period->from);
        $periodEnd = CarbonImmutable::parse($period->to);
        $cursor = $periodStart->startOfWeek(CarbonImmutable::MONDAY);

        while ($cursor <= $periodEnd) {
            $weekEnd = $cursor->endOfWeek(CarbonImmutable::SUNDAY);
            $from = $cursor->lessThan($periodStart) ? $periodStart : $cursor;
            $to = $weekEnd->greaterThan($periodEnd) ? $periodEnd : $weekEnd;
            $isPartial = $cursor->lessThan($periodStart) || $weekEnd->greaterThan($periodEnd);

            $buckets[] = [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $cursor->format('d/m').' - '.$weekEnd->format('d/m'),
                'is_partial' => $isPartial,
            ];
            $cursor = $cursor->addWeek();
        }

        return $buckets;
    }

    /** @return list<array{from: string, to: string, label: string, is_partial: bool}> */
    private function monthlyBuckets(DashboardPeriodData $period): array
    {
        $buckets = [];
        $periodStart = CarbonImmutable::parse($period->from);
        $periodEnd = CarbonImmutable::parse($period->to);
        $cursor = $periodStart->startOfMonth();

        while ($cursor <= $periodEnd) {
            $monthEnd = $cursor->endOfMonth();
            $from = $cursor->lessThan($periodStart) ? $periodStart : $cursor;
            $to = $monthEnd->greaterThan($periodEnd) ? $periodEnd : $monthEnd;
            $isPartial = $cursor->lessThan($periodStart) || $monthEnd->greaterThan($periodEnd);

            $buckets[] = [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $cursor->format('m/Y'),
                'is_partial' => $isPartial,
            ];
            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $buckets;
    }
}
