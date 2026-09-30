<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\FinancialAccount;
use App\Models\FinancialGoal;
use App\Models\NotificationEvent;
use App\Models\NotificationProjectionFact;
use App\Models\User;
use App\Services\Notifications\NotificationFactDispatcher;
use App\Services\Notifications\NotificationReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GoalMilestoneNotificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function allocation_reaches_target_once_without_completing_goal_or_moving_money(): void
    {
        $user = $this->signIn();
        $account = FinancialAccount::factory()->for($user)->create(['initial_balance_centavos' => 1000, 'current_balance_centavos' => 1000]);
        $goalId = $this->createGoal('Trip', 100, $account->id);
        $this->postJson("/api/v1/financial-goals/{$goalId}/allocations", ['amount_centavos' => 100], ['Idempotency-Key' => 'reach'])->assertOk();
        $this->getJson('/api/v1/notifications/summary')->assertOk()
            ->assertJsonPath('data.unread_count', 1)->assertJsonPath('data.requires_action_count', 0);

        $event = NotificationEvent::query()->where('type', 'goal_reached')->firstOrFail();
        self::assertNotNull($event->resolved_at);
        self::assertSame('active', FinancialGoal::query()->findOrFail($goalId)->status);
        self::assertSame(1000, $account->refresh()->current_balance_centavos);

        $this->postJson("/api/v1/financial-goals/{$goalId}/withdrawals", ['amount_centavos' => 10], ['Idempotency-Key' => 'fall'])->assertOk();
        $this->postJson("/api/v1/financial-goals/{$goalId}/allocations", ['amount_centavos' => 10], ['Idempotency-Key' => 'regain'])->assertOk();
        $this->drain();
        self::assertSame(1, NotificationEvent::query()->where('type', 'goal_reached')->count());
    }

    #[Test]
    public function new_target_gets_new_identity_and_completed_goal_does_not_requalify(): void
    {
        $this->signIn();
        $goalId = $this->createGoal('Reserve', 100);
        $this->postJson("/api/v1/financial-goals/{$goalId}/allocations", ['amount_centavos' => 100], ['Idempotency-Key' => 'reach-100'])->assertOk();
        $this->drain();
        $this->patchJson("/api/v1/financial-goals/{$goalId}", ['target_centavos' => 120], ['Idempotency-Key' => 'raise-target'])->assertOk();
        $this->postJson("/api/v1/financial-goals/{$goalId}/allocations", ['amount_centavos' => 20], ['Idempotency-Key' => 'reach-120'])->assertOk();
        $this->drain();
        self::assertSame(2, NotificationEvent::query()->where('type', 'goal_reached')->count());
        self::assertSame([100, 120], NotificationEvent::query()->where('type', 'goal_reached')->orderBy('id')->get()
            ->map(fn (NotificationEvent $event): int => (int) $event->business_context['target_centavos'])->all());

        $this->postJson("/api/v1/financial-goals/{$goalId}/complete", [], ['Idempotency-Key' => 'complete'])->assertOk();
        $this->drain();
        self::assertSame('completed', FinancialGoal::query()->findOrFail($goalId)->status);
        self::assertSame(2, NotificationEvent::query()->where('type', 'goal_reached')->count());
    }

    #[Test]
    public function brief_reach_before_fact_drain_is_retained_after_withdrawal(): void
    {
        $this->signIn();
        $goalId = $this->createGoal('Education', 100);
        $this->postJson("/api/v1/financial-goals/{$goalId}/allocations", ['amount_centavos' => 100], ['Idempotency-Key' => 'brief-reach'])->assertOk();
        $this->postJson("/api/v1/financial-goals/{$goalId}/withdrawals", ['amount_centavos' => 30], ['Idempotency-Key' => 'brief-fall'])->assertOk();
        self::assertSame(0, NotificationEvent::query()->count());
        $this->drain();
        self::assertSame(1, NotificationEvent::query()->where('type', 'goal_reached')->count());
        self::assertSame(70, (int) $this->getJson("/api/v1/financial-goals/{$goalId}")->assertOk()->json('data.allocated_centavos'));
    }

    #[Test]
    public function archived_goal_does_not_emit_a_milestone(): void
    {
        $this->signIn();
        $goalId = $this->createGoal('Dormant', 100);
        $this->postJson("/api/v1/financial-goals/{$goalId}/archive", [], ['Idempotency-Key' => 'archive'])->assertOk();
        $this->drain();
        self::assertSame(0, NotificationEvent::query()->count());
        self::assertSame(2, NotificationProjectionFact::query()->where('source_kind', 'goal')->count());
    }

    private function signIn(): User
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        return $user;
    }

    private function createGoal(string $name, int $target, ?int $accountId = null): int
    {
        $payload = ['name' => $name, 'target_centavos' => $target];
        if ($accountId !== null) {
            $payload['financial_account_id'] = $accountId;
        }

        return (int) $this->postJson('/api/v1/financial-goals', $payload, ['Idempotency-Key' => 'create-'.$name])
            ->assertCreated()->json('data.id');
    }

    private function drain(): void
    {
        $result = app(NotificationReconciler::class)->drain(app(NotificationFactDispatcher::class)->handle(...));
        self::assertSame(0, $result['failed']);
    }
}
