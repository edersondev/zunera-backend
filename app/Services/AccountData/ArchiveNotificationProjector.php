<?php

declare(strict_types=1);

namespace App\Services\AccountData;

use App\Models\CreditCardStatement;
use App\Models\FinancialGoal;
use App\Models\RecurringCardOccurrence;
use App\Models\Transaction;
use App\Services\Notifications\BudgetNotificationProjector;
use App\Services\Notifications\GoalMilestoneProjector;
use App\Services\Notifications\RecurringReviewProjector;
use App\Services\Notifications\StatementNotificationProjector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ArchiveNotificationProjector
{
    public function __construct(
        private readonly StatementNotificationProjector $statements,
        private readonly BudgetNotificationProjector $budgets,
        private readonly RecurringReviewProjector $recurring,
        private readonly GoalMilestoneProjector $goals,
    ) {}

    /** @param array<string, array<int, int>> $ids */
    public function capture(int $userId, array $ids): void
    {
        foreach ($ids['credit_card_statements'] ?? [] as $id) {
            $this->statements->capture(CreditCardStatement::query()->findOrFail($id));
        }

        $today = CarbonImmutable::now('America/Sao_Paulo');
        foreach ($ids['monthly_budgets'] ?? [] as $id) {
            $budget = DB::table('monthly_budgets')->find($id);
            if ((int) $budget->budget_year === $today->year && (int) $budget->budget_month === $today->month) {
                $this->budgets->captureMonth($userId, $today->year, $today->month);
                break;
            }
        }

        foreach ($ids['transactions'] ?? [] as $id) {
            $this->recurring->captureTransaction(Transaction::query()->findOrFail($id));
        }
        foreach ($ids['recurring_card_occurrences'] ?? [] as $id) {
            $this->recurring->captureCard(RecurringCardOccurrence::query()->findOrFail($id));
        }
        foreach ($ids['financial_goals'] ?? [] as $id) {
            $this->goals->capture(FinancialGoal::query()->findOrFail($id));
        }
    }
}
