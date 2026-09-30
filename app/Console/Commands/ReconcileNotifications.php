<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\BudgetNotificationProjector;
use App\Services\Notifications\NotificationFactDispatcher;
use App\Services\Notifications\NotificationReconciler;
use App\Services\Notifications\StatementNotificationProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class ReconcileNotifications extends Command
{
    protected $signature = 'notifications:reconcile {--limit=100 : Maximum source facts per run}';

    protected $description = 'Reconcile notification projection facts and retention';

    public function handle(NotificationReconciler $reconciler, NotificationFactDispatcher $dispatcher, StatementNotificationProjector $statements, BudgetNotificationProjector $budgets): int
    {
        $limit = max(1, min((int) $this->option('limit'), 500));
        $result = $reconciler->drain($dispatcher->handle(...), $limit);
        $dateCandidates = $statements->scanDateCandidates();
        $stale = $statements->reconcileActive();
        $budgetCandidates = $budgets->scanCurrentMonth();
        $budgetStale = $budgets->reconcileActive();
        $expired = $reconciler->expireResolved();
        Cache::put('notifications.scheduler_heartbeat', now()->toIso8601String(), now()->addMinutes(10));
        $this->components->info("Processed {$result['processed']} facts; {$result['failed']} failed; {$dateCandidates} statement candidates; {$stale} active statements; {$budgetCandidates} budget candidates; {$budgetStale} active budgets; {$expired} expired.");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
