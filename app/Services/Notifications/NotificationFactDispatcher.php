<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationProjectionFact;

final class NotificationFactDispatcher
{
    public function __construct(
        private readonly StatementNotificationProjector $statements,
        private readonly RecurringReviewProjector $recurrences,
        private readonly BudgetNotificationProjector $budgets,
        private readonly GoalMilestoneProjector $goals,
    ) {}

    public function handle(NotificationProjectionFact $fact): void
    {
        if ($fact->source_kind === 'credit_card_statement') {
            $this->statements->handleFact($fact);

            return;
        }
        if (in_array($fact->source_kind, ['transaction', 'recurring_card_occurrence'], true)) {
            $this->recurrences->handleFact($fact);

            return;
        }
        if ($fact->source_kind === 'budget_plan') {
            $this->budgets->handleFact($fact);

            return;
        }
        if ($fact->source_kind === 'goal') {
            $this->goals->handleFact($fact);

            return;
        }

        throw new \LogicException('No notification projector for '.$fact->source_kind);
    }
}
