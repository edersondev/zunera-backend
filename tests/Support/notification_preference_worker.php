<?php

declare(strict_types=1);

use App\Models\BudgetCategoryPlan;
use App\Models\CreditCardStatement;
use App\Models\FinancialGoal;
use App\Models\Transaction;
use App\Services\Notifications\BudgetNotificationProjector;
use App\Services\Notifications\GoalMilestoneProjector;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\RecurringReviewProjector;
use App\Services\Notifications\StatementNotificationProjector;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $barrier, $ready, $action, $userId, $category, $sourceKind, $sourceId] = $argv;
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(1000);
}

if ($action === 'toggle') {
    app(NotificationPreferenceService::class)->set((int) $userId, $category, false);

    exit(0);
}

DB::transaction(function () use ($sourceKind, $sourceId): void {
    match ($sourceKind) {
        'credit_card_statement' => app(StatementNotificationProjector::class)->capture(CreditCardStatement::query()->findOrFail((int) $sourceId)),
        'transaction' => app(RecurringReviewProjector::class)->captureTransaction(Transaction::query()->findOrFail((int) $sourceId)),
        'budget_plan' => (function () use ($sourceId): void {
            $plan = BudgetCategoryPlan::query()->with('monthlyBudget')->findOrFail((int) $sourceId);
            app(BudgetNotificationProjector::class)->captureMonth((int) $plan->monthlyBudget->user_id,
                (int) $plan->monthlyBudget->budget_year, (int) $plan->monthlyBudget->budget_month);
        })(),
        'goal' => app(GoalMilestoneProjector::class)->capture(FinancialGoal::query()->findOrFail((int) $sourceId)),
    };
});
