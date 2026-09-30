<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Data\Notifications\NotificationCandidate;
use App\Models\BudgetCategoryPlan;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\FinancialGoal;
use App\Models\MonthlyBudget;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use App\Services\Notifications\NotificationSourceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotificationAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_sees_typed_statement_destination_but_changed_owner_is_hidden(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $card = CreditCard::factory()->withUser($owner)->create();
        $statement = CreditCardStatement::factory()->forCard($card)->closed()->create();
        $event = $this->event((int) $owner->id, 'credit_card_statement', (int) $statement->id, 'statement_due_today');
        $resolver = app(NotificationSourceResolver::class);

        self::assertSame([
            'accessible' => true,
            'source_available' => true,
            'destination' => ['kind' => 'credit_card_statement', 'params' => ['statement_id' => $statement->id]],
        ], $resolver->inspect($event));

        $statement->user_id = $other->id;
        $statement->save();
        self::assertFalse($resolver->inspect($event)['accessible']);
    }

    #[Test]
    public function deleted_source_keeps_safe_history_without_destination(): void
    {
        $owner = User::factory()->create();
        $card = CreditCard::factory()->withUser($owner)->create();
        $statement = CreditCardStatement::factory()->forCard($card)->closed()->create();
        $event = $this->event((int) $owner->id, 'credit_card_statement', (int) $statement->id, 'statement_due_today');
        $statement->delete();

        self::assertSame([
            'accessible' => true,
            'source_available' => false,
            'destination' => null,
        ], app(NotificationSourceResolver::class)->inspect($event));
    }

    #[Test]
    public function budget_and_goal_destinations_use_source_owned_context(): void
    {
        $owner = User::factory()->create();
        $month = MonthlyBudget::factory()->create(['user_id' => $owner->id, 'budget_year' => 2026, 'budget_month' => 9]);
        $plan = BudgetCategoryPlan::factory()->create(['monthly_budget_id' => $month->id]);
        $goal = FinancialGoal::query()->create(['user_id' => $owner->id, 'name' => 'Trip', 'target_centavos' => 10000, 'status' => 'active']);
        $resolver = app(NotificationSourceResolver::class);

        self::assertSame(['kind' => 'budget_plan', 'params' => ['year' => 2026, 'month' => 9, 'plan_id' => $plan->id]],
            $resolver->inspect($this->event((int) $owner->id, 'budget_plan', (int) $plan->id, 'budget_exceeded'))['destination']);
        self::assertSame(['kind' => 'goal', 'params' => ['goal_id' => $goal->id]],
            $resolver->inspect($this->event((int) $owner->id, 'goal', (int) $goal->id, 'goal_reached'))['destination']);
    }

    private function event(int $userId, string $sourceKind, int $sourceId, string $type): NotificationEvent
    {
        $candidate = new NotificationCandidate(
            userId: $userId,
            type: $type,
            category: 'credit_cards',
            severity: 'attention',
            sourceKind: $sourceKind,
            sourceId: $sourceId,
            identity: ['stage' => $type],
            eventAt: CarbonImmutable::now(),
            snapshot: ['label' => 'Safe historical label'],
        );

        return app(NotificationEventService::class)->qualify($candidate, true);
    }
}
