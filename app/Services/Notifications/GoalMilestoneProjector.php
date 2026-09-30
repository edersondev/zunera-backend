<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Data\Notifications\NotificationCandidate;
use App\Data\Notifications\NotificationProjectionData;
use App\Models\FinancialGoal;
use App\Models\NotificationProjectionFact;
use App\Services\FinancialGoals\FinancialGoalCapacityService;
use Carbon\CarbonImmutable;

final class GoalMilestoneProjector
{
    public function __construct(
        private readonly FinancialGoalCapacityService $capacity,
        private readonly NotificationProjectionFactService $facts,
        private readonly NotificationEventService $events,
        private readonly NotificationPreferenceService $preferences,
    ) {}

    public function capture(FinancialGoal $goal): void
    {
        $reached = $goal->status === 'active'
            && $this->capacity->allocationForGoal((int) $goal->id) >= (int) $goal->target_centavos;
        $this->facts->capture(new NotificationProjectionData(
            userId: (int) $goal->user_id,
            sourceKind: 'goal',
            sourceId: (int) $goal->id,
            qualifiedType: $reached ? 'goal_reached' : null,
            qualifiedAt: CarbonImmutable::now(),
            context: $reached ? ['target_centavos' => (int) $goal->target_centavos, 'goal_name' => (string) $goal->name] : [],
        ));
    }

    public function handleFact(NotificationProjectionFact $fact): void
    {
        $goal = FinancialGoal::query()->where('user_id', $fact->user_id)->find($fact->source_id);
        if ($goal === null) {
            return;
        }
        if ($fact->qualified_type === 'goal_reached' && $fact->qualified_at !== null) {
            $target = $fact->context['target_centavos'] ?? null;
            if (is_int($target) && $target > 0) {
                $at = CarbonImmutable::instance($fact->qualified_at);
                $candidate = $this->candidate($goal, $target, (string) ($fact->context['goal_name'] ?? $goal->name), $at);
                $this->events->qualify($candidate, $this->preferences->enabledAt((int) $fact->user_id, 'financial_goals', $at));
            }
        }

        if ($goal->status === 'active' && $this->capacity->allocationForGoal((int) $goal->id) >= (int) $goal->target_centavos) {
            $at = CarbonImmutable::now();
            $this->events->qualify($this->candidate($goal, (int) $goal->target_centavos, (string) $goal->name, $at),
                $this->preferences->enabledAt((int) $goal->user_id, 'financial_goals', $at));
        }
    }

    private function candidate(FinancialGoal $goal, int $target, string $name, CarbonImmutable $at): NotificationCandidate
    {
        $name = mb_substr(trim(strip_tags($name)), 0, 80);
        $amount = 'R$ '.number_format($target / 100, 2, ',', '.');

        return new NotificationCandidate(
            userId: (int) $goal->user_id,
            type: 'goal_reached',
            category: 'financial_goals',
            severity: 'success',
            sourceKind: 'goal',
            sourceId: (int) $goal->id,
            identity: ['target_centavos' => $target],
            eventAt: $at,
            snapshot: [
                'title' => ['pt-BR' => 'Meta alcançada', 'en' => 'Goal reached'],
                'summary' => ['pt-BR' => "{$name} alcançou {$amount}.", 'en' => "{$name} reached {$amount}."],
            ],
            actionable: false,
        );
    }
}
