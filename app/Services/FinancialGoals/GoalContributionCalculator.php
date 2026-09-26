<?php

declare(strict_types=1);

namespace App\Services\FinancialGoals;

use Carbon\CarbonImmutable;

final class GoalContributionCalculator
{
    /** @return array{target_date_state: ?string, remaining_calendar_days: ?int, contribution_periods_remaining: ?int, suggested_monthly_centavos: ?int} */
    public function calculate(?string $targetDate, int $remainingCentavos, string $status, ?CarbonImmutable $today = null): array
    {
        if ($targetDate === null) {
            return ['target_date_state' => null, 'remaining_calendar_days' => null, 'contribution_periods_remaining' => null, 'suggested_monthly_centavos' => null];
        }
        $today ??= CarbonImmutable::now('America/Sao_Paulo')->startOfDay();
        $target = CarbonImmutable::parse($targetDate, 'America/Sao_Paulo')->startOfDay();
        $days = (int) $today->diffInDays($target, false);
        $state = $remainingCentavos === 0 ? 'met' : ($days < 0 ? 'overdue' : ($days === 0 ? 'due_today' : 'future'));
        $periods = null;
        $suggestion = null;
        if ($status === 'active' && $remainingCentavos > 0 && $days > 0) {
            $periods = ($target->year - $today->year) * 12 + $target->month - $today->month + 1;
            $suggestion = intdiv($remainingCentavos + $periods - 1, $periods);
        }

        return ['target_date_state' => $state, 'remaining_calendar_days' => $days, 'contribution_periods_remaining' => $periods, 'suggested_monthly_centavos' => $suggestion];
    }
}
