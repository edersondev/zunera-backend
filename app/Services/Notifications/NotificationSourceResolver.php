<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\BudgetCategoryPlan;
use App\Models\CreditCardStatement;
use App\Models\FinancialGoal;
use App\Models\NotificationEvent;
use App\Models\RecurringCardOccurrence;
use App\Models\Transaction;

final class NotificationSourceResolver
{
    /** @return array{accessible: bool, source_available: bool, destination: array<string, mixed>|null} */
    public function inspect(NotificationEvent $event): array
    {
        $source = match ($event->source_kind) {
            'credit_card_statement' => CreditCardStatement::query()->find($event->source_id),
            'transaction' => Transaction::query()->find($event->source_id),
            'recurring_card_occurrence' => RecurringCardOccurrence::query()->find($event->source_id),
            'budget_plan' => BudgetCategoryPlan::query()->with('monthlyBudget')->find($event->source_id),
            'goal' => FinancialGoal::query()->find($event->source_id),
            default => null,
        };

        if ($source === null) {
            return ['accessible' => true, 'source_available' => false, 'destination' => null];
        }

        $ownerId = $source instanceof BudgetCategoryPlan ? $source->monthlyBudget?->user_id : $source->user_id;
        if ((int) $ownerId !== (int) $event->user_id) {
            return ['accessible' => false, 'source_available' => false, 'destination' => null];
        }

        $destination = match ($event->source_kind) {
            'credit_card_statement' => ['kind' => 'credit_card_statement', 'params' => ['statement_id' => (int) $source->id]],
            'transaction' => $source->removed_at === null ? ['kind' => 'transaction', 'params' => ['transaction_id' => (int) $source->id]] : null,
            'recurring_card_occurrence' => ['kind' => 'recurring_card_occurrence', 'params' => ['rule_id' => (int) $source->recurring_transaction_id, 'occurrence_id' => (int) $source->id]],
            'budget_plan' => ['kind' => 'budget_plan', 'params' => ['year' => (int) $source->monthlyBudget->budget_year, 'month' => (int) $source->monthlyBudget->budget_month, 'plan_id' => (int) $source->id]],
            'goal' => ['kind' => 'goal', 'params' => ['goal_id' => (int) $source->id]],
        };

        return ['accessible' => true, 'source_available' => $destination !== null, 'destination' => $destination];
    }
}
