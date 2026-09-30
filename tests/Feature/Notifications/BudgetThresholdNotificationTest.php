<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\Transactions\TransactionStatus;
use App\Models\BudgetCategoryPlan;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\MonthlyBudget;
use App\Models\NotificationEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notifications\BudgetNotificationProjector;
use App\Services\Notifications\NotificationFactDispatcher;
use App\Services\Notifications\NotificationReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BudgetThresholdNotificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function realized_plan_crosses_eighty_reached_and_exceeded_as_distinct_stages(): void
    {
        [$user, $plan, $category] = $this->plan();
        $projector = app(BudgetNotificationProjector::class);
        $this->spend($user, $category, 8000);
        $projector->evaluatePlan((int) $user->id, (int) $plan->id);
        $this->assertVisible('budget_approaching');

        $this->spend($user, $category, 2000);
        $projector->evaluatePlan((int) $user->id, (int) $plan->id);
        $this->assertVisible('budget_reached');
        self::assertNotNull(NotificationEvent::query()->where('type', 'budget_approaching')->firstOrFail()->resolved_at);

        $this->spend($user, $category, 1);
        $projector->evaluatePlan((int) $user->id, (int) $plan->id);
        $this->assertVisible('budget_exceeded');
        self::assertSame(3, NotificationEvent::query()->where('visibility', 'visible')->count());
    }

    #[Test]
    public function direct_jump_to_exceeded_consumes_lower_stages_and_recross_uses_same_identity(): void
    {
        [$user, $plan, $category] = $this->plan();
        $transaction = $this->spend($user, $category, 11000);
        DB::transaction(fn () => app(BudgetNotificationProjector::class)->captureMonth((int) $user->id, 2026, 9));
        $this->drain();
        $event = NotificationEvent::query()->where('type', 'budget_exceeded')->firstOrFail();
        self::assertNull($event->resolved_at);
        self::assertSame(2, NotificationEvent::query()->where('visibility', 'suppressed')->count());

        $transaction->amount_centavos = 7000;
        $transaction->save();
        app(BudgetNotificationProjector::class)->evaluatePlan((int) $user->id, (int) $plan->id);
        self::assertNotNull($event->refresh()->resolved_at);
        $transaction->amount_centavos = 12000;
        $transaction->save();
        app(BudgetNotificationProjector::class)->evaluatePlan((int) $user->id, (int) $plan->id);
        self::assertNull($event->refresh()->resolved_at);
        self::assertNotNull($event->read_at);
        self::assertSame(1, NotificationEvent::query()->where('type', 'budget_exceeded')->count());
    }

    #[Test]
    public function projected_only_spend_and_monthly_total_do_not_qualify_category_plan(): void
    {
        [$user, $plan, $category] = $this->plan();
        $this->spend($user, $category, 15000, TransactionStatus::Pending);
        app(BudgetNotificationProjector::class)->evaluatePlan((int) $user->id, (int) $plan->id);
        self::assertSame(0, NotificationEvent::query()->where('visibility', 'visible')->count());

        $other = Category::factory()->create(['user_id' => $user->id]);
        $this->spend($user, $other, 15000);
        app(BudgetNotificationProjector::class)->evaluatePlan((int) $user->id, (int) $plan->id);
        self::assertSame(0, NotificationEvent::query()->where('visibility', 'visible')->count());
    }

    #[Test]
    public function ended_month_correction_cannot_emit_and_rollover_resolves_previous_month(): void
    {
        [$user, $plan, $category] = $this->plan();
        $this->spend($user, $category, 8500);
        app(BudgetNotificationProjector::class)->evaluatePlan((int) $user->id, (int) $plan->id);
        $event = NotificationEvent::query()->where('visibility', 'visible')->firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:01:00', 'America/Sao_Paulo'));
        app(BudgetNotificationProjector::class)->reconcileActive();
        self::assertNotNull($event->refresh()->resolved_at);

        $this->spend($user, $category, 1500);
        DB::transaction(fn () => app(BudgetNotificationProjector::class)->captureMonth((int) $user->id, 2026, 9));
        $this->drain();
        self::assertSame(0, NotificationEvent::query()->where('type', 'budget_reached')->where('visibility', 'visible')->count());
    }

    #[Test]
    public function accepted_expense_captures_budget_fact_and_center_uses_source_status(): void
    {
        [$user, $plan, $category] = $this->plan();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id, 'initial_balance_centavos' => 20000, 'current_balance_centavos' => 20000]);
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $this->postJson('/api/v1/transactions', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'description' => 'Food',
            'amount_centavos' => 9000,
            'transaction_date' => '2026-09-15',
        ], ['Idempotency-Key' => 'budget-expense'])->assertCreated();
        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonPath('data.requires_action_count', 1);
        $event = NotificationEvent::query()->where('source_id', $plan->id)->where('source_kind', 'budget_plan')->firstOrFail();
        self::assertSame('budget_approaching', $event->type);
        self::assertSame(9000, $event->current_context['realized_centavos']);
    }

    /** @return array{User, BudgetCategoryPlan, Category} */
    private function plan(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00:00', 'America/Sao_Paulo'));
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id]);
        $month = MonthlyBudget::factory()->create(['user_id' => $user->id, 'budget_year' => 2026, 'budget_month' => 9]);
        $plan = BudgetCategoryPlan::factory()->create([
            'monthly_budget_id' => $month->id,
            'category_id' => $category->id,
            'planned_amount_centavos' => 10000,
            'category_name_snapshot' => 'Food',
        ]);

        return [$user, $plan, $category];
    }

    private function spend(User $user, Category $category, int $amount, TransactionStatus $status = TransactionStatus::Effective): Transaction
    {
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);

        return Transaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'status' => $status,
            'amount_centavos' => $amount,
            'transaction_date' => '2026-09-15',
        ]);
    }

    private function assertVisible(string $type): void
    {
        self::assertNull(NotificationEvent::query()->where('type', $type)->where('visibility', 'visible')->firstOrFail()->resolved_at);
    }

    private function drain(): void
    {
        $result = app(NotificationReconciler::class)->drain(app(NotificationFactDispatcher::class)->handle(...));
        self::assertSame(0, $result['failed']);
    }
}
