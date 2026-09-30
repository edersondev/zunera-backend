<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Data\Notifications\NotificationCandidate;
use App\Data\Notifications\NotificationProjectionData;
use App\Enums\Budgets\BudgetStatus;
use App\Models\BudgetCategoryPlan;
use App\Models\MonthlyBudget;
use App\Models\NotificationEvent;
use App\Models\NotificationProjectionFact;
use App\Models\User;
use App\Services\Budgets\BudgetCalculationService;
use App\Services\Budgets\BudgetMonthResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class BudgetNotificationProjector
{
    private const STAGES = ['budget_approaching', 'budget_reached', 'budget_exceeded'];

    public function __construct(
        private readonly BudgetCalculationService $calculations,
        private readonly BudgetMonthResolver $months,
        private readonly NotificationEventService $events,
        private readonly NotificationPreferenceService $preferences,
        private readonly NotificationProjectionFactService $facts,
    ) {}

    public function captureMonth(int $userId, int $year, int $month): void
    {
        $budget = MonthlyBudget::query()->where('user_id', $userId)
            ->where('budget_year', $year)->where('budget_month', $month)->first();
        if ($budget === null) {
            return;
        }
        $user = User::query()->findOrFail($userId);
        $calculation = $this->calculations->forMonth($user, $budget);
        foreach ($budget->plans()->get() as $plan) {
            $item = $calculation->plans[(int) $plan->id] ?? null;
            $stage = $item === null || ! $this->isCurrent($year, $month) ? null : $this->stage($item->status);
            $this->facts->capture(new NotificationProjectionData(
                userId: $userId,
                sourceKind: 'budget_plan',
                sourceId: (int) $plan->id,
                affectedYear: $year,
                affectedMonth: $month,
                qualifiedType: $stage,
                qualifiedAt: CarbonImmutable::now(),
                context: $item === null ? [] : [
                    'realized_centavos' => $item->realizedCentavos,
                    'planned_centavos' => $item->plannedCentavos,
                    'category_name' => $plan->category_name_snapshot,
                ],
            ));
        }
    }

    public function captureRemoved(int $userId, int $planId, int $year, int $month): void
    {
        $this->facts->capture(new NotificationProjectionData($userId, 'budget_plan', $planId, $year, $month));
    }

    public function captureDate(int $userId, string $date): void
    {
        $day = CarbonImmutable::parse($date);
        $this->captureMonth($userId, (int) $day->year, (int) $day->month);
    }

    public function handleFact(NotificationProjectionFact $fact): void
    {
        $plan = $this->ownedPlan((int) $fact->user_id, (int) $fact->source_id);
        if ($plan === null) {
            $this->resolveMissing((int) $fact->user_id, (int) $fact->source_id);

            return;
        }

        $captured = $fact->qualified_type;
        if (in_array($captured, self::STAGES, true) && $fact->qualified_at !== null) {
            $context = $fact->context;
            $candidate = $this->candidate($plan, $captured, CarbonImmutable::instance($fact->qualified_at),
                (int) ($context['realized_centavos'] ?? 0), (int) ($context['planned_centavos'] ?? $plan->planned_amount_centavos),
                (string) ($context['category_name'] ?? $plan->category_name_snapshot));
            $this->events->qualify($candidate, $this->preferences->enabledAt((int) $fact->user_id, 'budgets', CarbonImmutable::instance($fact->qualified_at)));
        }

        $this->evaluatePlan((int) $fact->user_id, (int) $fact->source_id);
    }

    public function evaluatePlan(int $userId, int $planId): void
    {
        $plan = $this->ownedPlan($userId, $planId);
        if ($plan === null) {
            $this->resolveMissing($userId, $planId);

            return;
        }
        $month = $plan->monthlyBudget;
        $current = $this->isCurrent((int) $month->budget_year, (int) $month->budget_month);
        $calculation = $current ? $this->calculations->forMonth($month->user, $month) : null;
        $item = $calculation?->plans[$planId] ?? null;
        $stage = $item === null ? null : $this->stage($item->status);
        foreach (self::STAGES as $index => $type) {
            $candidate = $this->candidate($plan, $type, CarbonImmutable::now(), $item?->realizedCentavos ?? 0, $item?->plannedCentavos ?? (int) $plan->planned_amount_centavos, (string) $plan->category_name_snapshot);
            if ($type === $stage) {
                $this->events->qualify($candidate, $this->preferences->enabledAt($userId, 'budgets', CarbonImmutable::now()));
            } elseif ($stage !== null && $index < array_search($stage, self::STAGES, true)) {
                $this->events->resolve($candidate);
                $this->events->consume($candidate);
            } else {
                $this->events->resolve($candidate);
            }
        }
    }

    public function reconcileActive(): int
    {
        $active = NotificationEvent::query()->where('source_kind', 'budget_plan')
            ->where('visibility', 'visible')->whereNull('resolved_at')
            ->select(['user_id', 'source_id'])->distinct()->get();
        foreach ($active as $event) {
            $this->evaluatePlan((int) $event->user_id, (int) $event->source_id);
        }

        return $active->count();
    }

    public function scanCurrentMonth(int $limit = 5000): int
    {
        $today = CarbonImmutable::now('America/Sao_Paulo');
        $cursor = (int) Cache::get('notifications.budget_scan_cursor', 0);
        $plans = BudgetCategoryPlan::query()->where('id', '>', $cursor)
            ->whereHas('monthlyBudget', fn ($query) => $query->where('budget_year', $today->year)->where('budget_month', $today->month))
            ->orderBy('id')->limit(max(1, min($limit, 5000)))->get();
        foreach ($plans as $plan) {
            $this->evaluatePlan((int) $plan->monthlyBudget->user_id, (int) $plan->id);
        }
        Cache::put('notifications.budget_scan_cursor', $plans->count() === $limit ? (int) $plans->last()->id : 0, now()->addDay());

        return $plans->count();
    }

    private function ownedPlan(int $userId, int $planId): ?BudgetCategoryPlan
    {
        return BudgetCategoryPlan::query()->with('monthlyBudget.user')->whereHas('monthlyBudget', fn ($query) => $query->where('user_id', $userId))->find($planId);
    }

    private function resolveMissing(int $userId, int $planId): void
    {
        NotificationEvent::query()->where('user_id', $userId)->where('source_kind', 'budget_plan')
            ->where('source_id', $planId)->where('visibility', 'visible')->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'read_at' => now(), 'current_context' => null]);
    }

    private function stage(BudgetStatus $status): ?string
    {
        return match ($status) {
            BudgetStatus::Approaching => 'budget_approaching',
            BudgetStatus::Reached => 'budget_reached',
            BudgetStatus::Exceeded => 'budget_exceeded',
            default => null,
        };
    }

    private function isCurrent(int $year, int $month): bool
    {
        $today = CarbonImmutable::now('America/Sao_Paulo');

        return $today->year === $year && $today->month === $month && ! $this->months->isEnded($year, $month);
    }

    private function candidate(BudgetCategoryPlan $plan, string $type, CarbonImmutable $at, int $realized, int $planned, string $name): NotificationCandidate
    {
        $month = $plan->monthlyBudget;
        $name = mb_substr(trim(strip_tags($name)), 0, 80);
        $amount = 'R$ '.number_format($realized / 100, 2, ',', '.');
        $limit = 'R$ '.number_format($planned / 100, 2, ',', '.');
        $titles = match ($type) {
            'budget_approaching' => ['pt-BR' => 'Plano perto do limite', 'en' => 'Plan approaching limit'],
            'budget_reached' => ['pt-BR' => 'Plano atingiu o limite', 'en' => 'Plan reached limit'],
            default => ['pt-BR' => 'Plano excedeu o limite', 'en' => 'Plan exceeded limit'],
        };
        $summary = [
            'pt-BR' => "{$name}: realizado {$amount} de {$limit} em {$month->budget_month}/{$month->budget_year}.",
            'en' => "{$name}: realized {$amount} of {$limit} in {$month->budget_month}/{$month->budget_year}.",
        ];

        return new NotificationCandidate(
            userId: (int) $month->user_id,
            type: $type,
            category: 'budgets',
            severity: $type === 'budget_exceeded' ? 'critical' : 'attention',
            sourceKind: 'budget_plan',
            sourceId: (int) $plan->id,
            identity: ['year' => (int) $month->budget_year, 'month' => (int) $month->budget_month, 'stage' => $type],
            eventAt: $at,
            snapshot: ['title' => $titles, 'summary' => $summary],
            currentContext: ['realized_centavos' => $realized, 'planned_centavos' => $planned, 'summary' => $summary],
        );
    }
}
