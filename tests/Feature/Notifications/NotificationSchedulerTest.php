<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Data\Notifications\NotificationProjectionData;
use App\Models\BudgetCategoryPlan;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\FinancialAccount;
use App\Models\FinancialGoal;
use App\Models\FinancialGoalActivity;
use App\Models\MonthlyBudget;
use App\Models\NotificationEvent;
use App\Models\NotificationProjectionFact;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notifications\GoalMilestoneProjector;
use App\Services\Notifications\NotificationFactDispatcher;
use App\Services\Notifications\NotificationProjectionFactService;
use App\Services\Notifications\NotificationReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotificationSchedulerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function command_records_heartbeat_and_expires_only_old_resolved_content(): void
    {
        Cache::forget('notifications.scheduler_heartbeat');
        $user = User::factory()->create();
        NotificationEvent::query()->create([
            'user_id' => $user->id, 'event_key' => 'old', 'type' => 'goal_reached', 'category' => 'financial_goals',
            'severity' => 'success', 'source_kind' => 'goal', 'source_id' => 1, 'event_at' => now()->subDays(100),
            'resolved_at' => now()->subDays(99), 'visibility' => 'visible', 'event_snapshot' => ['label' => 'private'],
        ])->forceFill(['created_at' => now()->subDays(100)])->save();

        $this->artisan('notifications:reconcile')->assertExitCode(0);
        self::assertNotNull(Cache::get('notifications.scheduler_heartbeat'));
        $event = NotificationEvent::query()->firstOrFail();
        self::assertSame('expired', $event->visibility);
        self::assertNull($event->event_snapshot);
    }

    #[Test]
    public function unsupported_fact_is_retried_and_not_lost(): void
    {
        $user = User::factory()->create();
        DB::transaction(fn () => app(NotificationProjectionFactService::class)->capture(new NotificationProjectionData((int) $user->id, 'unknown', 8)));

        $this->artisan('notifications:reconcile')->assertExitCode(1);
        $fact = NotificationProjectionFact::query()->firstOrFail();
        self::assertNull($fact->processed_at);
        self::assertSame(1, $fact->attempts);
    }

    #[Test]
    public function injected_evaluator_failure_recovers_without_duplicate_delivery(): void
    {
        $user = User::factory()->create();
        $goal = FinancialGoal::query()->create([
            'user_id' => $user->id, 'name' => 'Recovery goal', 'target_centavos' => 100, 'status' => 'active',
        ]);
        FinancialGoalActivity::query()->create([
            'financial_goal_id' => $goal->id, 'user_id' => $user->id, 'type' => 'allocated',
            'amount_centavos' => 100, 'occurred_at' => now(), 'business_date' => now('America/Sao_Paulo')->toDateString(),
        ]);
        DB::transaction(fn () => app(GoalMilestoneProjector::class)->capture($goal));
        $reconciler = app(NotificationReconciler::class);
        $failure = $reconciler->drain(static function (): void {
            throw new \RuntimeException('Injected evaluator outage');
        }, userId: (int) $user->id);
        self::assertSame(['processed' => 0, 'failed' => 1], $failure);
        $fact = NotificationProjectionFact::query()->where('user_id', $user->id)->sole();
        self::assertNull($fact->processed_at);
        self::assertSame(1, $fact->attempts);
        self::assertSame(0, NotificationEvent::query()->where('user_id', $user->id)->count());

        $fact->available_at = now();
        $fact->save();
        $handler = app(NotificationFactDispatcher::class)->handle(...);
        self::assertSame(['processed' => 1, 'failed' => 0], $reconciler->drain($handler, userId: (int) $user->id));
        self::assertSame(['processed' => 0, 'failed' => 0], $reconciler->drain($handler, userId: (int) $user->id));
        self::assertSame(1, NotificationEvent::query()->where('user_id', $user->id)->where('type', 'goal_reached')->count());
    }

    #[Test]
    public function minute_command_finds_unpaid_statement_after_business_day_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 00:02:00', 'America/Sao_Paulo'));
        Cache::forget('notifications.statement_scan_cursor');
        $user = User::factory()->create();
        $card = CreditCard::factory()->withUser($user)->create();
        CreditCardStatement::factory()->forCard($card)->closed()
            ->closing('2026-09-10', '2026-09-17')
            ->create(['original_amount_centavos' => 10000]);

        $this->artisan('notifications:reconcile')->assertExitCode(0);
        self::assertSame(1, NotificationEvent::query()->where('type', 'statement_approaching')->count());
    }

    #[Test]
    public function minute_command_resolves_previous_budget_month_and_scans_current_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30 23:55:00', 'America/Sao_Paulo'));
        Cache::forget('notifications.budget_scan_cursor');
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id]);
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $september = MonthlyBudget::factory()->create(['user_id' => $user->id, 'budget_year' => 2026, 'budget_month' => 9]);
        BudgetCategoryPlan::factory()->create(['monthly_budget_id' => $september->id, 'category_id' => $category->id, 'planned_amount_centavos' => 10000]);
        Transaction::factory()->create(['user_id' => $user->id, 'financial_account_id' => $account->id, 'category_id' => $category->id, 'amount_centavos' => 9000, 'transaction_date' => '2026-09-30']);
        $this->artisan('notifications:reconcile')->assertExitCode(0);
        $old = NotificationEvent::query()->where('type', 'budget_approaching')->firstOrFail();
        self::assertNull($old->resolved_at);

        $october = MonthlyBudget::factory()->create(['user_id' => $user->id, 'budget_year' => 2026, 'budget_month' => 10]);
        BudgetCategoryPlan::factory()->create(['monthly_budget_id' => $october->id, 'category_id' => $category->id, 'planned_amount_centavos' => 10000]);
        Transaction::factory()->create(['user_id' => $user->id, 'financial_account_id' => $account->id, 'category_id' => $category->id, 'amount_centavos' => 10000, 'transaction_date' => '2026-10-01']);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:02:00', 'America/Sao_Paulo'));
        Cache::forget('notifications.budget_scan_cursor');
        $this->artisan('notifications:reconcile')->assertExitCode(0);
        self::assertNotNull($old->refresh()->resolved_at);
        self::assertSame(1, NotificationEvent::query()->where('type', 'budget_reached')->whereNull('resolved_at')->count());
    }
}
