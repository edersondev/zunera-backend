<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\NotificationEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notifications\NotificationReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotificationPrivacyRetentionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function removed_source_keeps_sanitized_history_without_action_or_stale_destination(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->withUser($user)->pending()->create();
        $event = $this->event($user, 'transaction', (int) $transaction->id, 'recurrence_review');
        $this->signIn($user);
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.requires_action', true)
            ->assertJsonPath('data.0.destination.params.transaction_id', $transaction->id);

        $transaction->removed_at = now();
        $transaction->save();

        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.title', 'Safe title')
            ->assertJsonPath('data.0.summary', 'Safe summary')
            ->assertJsonPath('data.0.source_available', false)
            ->assertJsonPath('data.0.requires_action', false)
            ->assertJsonPath('data.0.destination', null);
        $this->getJson('/api/v1/notifications/summary')->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.requires_action_count', 0);
        $this->postJson("/api/v1/notifications/{$event->id}/open")->assertOk()
            ->assertJsonPath('data.destination', null);
    }

    #[Test]
    public function deleted_source_keeps_only_safe_snapshot_and_no_destination(): void
    {
        $user = User::factory()->create();
        $card = CreditCard::factory()->withUser($user)->create();
        $statement = CreditCardStatement::factory()->forCard($card)->closed()->create();
        $event = $this->event($user, 'credit_card_statement', (int) $statement->id, 'statement_due_today');
        $statement->delete();
        $this->signIn($user);

        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.title', 'Safe title')
            ->assertJsonPath('data.0.source_available', false)
            ->assertJsonPath('data.0.destination', null);
        $this->getJson('/api/v1/notifications?view=requires_action')->assertJsonPath('data', []);
    }

    #[Test]
    public function lost_source_ownership_hides_text_count_and_actions_like_foreign_id(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $card = CreditCard::factory()->withUser($owner)->create();
        $statement = CreditCardStatement::factory()->forCard($card)->closed()->create();
        $event = $this->event($owner, 'credit_card_statement', (int) $statement->id, 'statement_due_today');
        $foreign = $this->event($other, 'goal', 999999, 'goal_reached');
        $this->signIn($owner);

        $statement->user_id = $other->id;
        $statement->save();
        $this->getJson('/api/v1/notifications/summary')->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.requires_action_count', 0);
        $this->getJson('/api/v1/notifications')->assertJsonPath('data', []);
        $this->postJson('/api/v1/notifications/read-all')->assertJsonPath('data.changed_count', 0);
        foreach ([$event->id, $foreign->id, 999999999] as $id) {
            $this->patchJson("/api/v1/notifications/{$id}/read")->assertNotFound();
            $this->postJson("/api/v1/notifications/{$id}/open")->assertNotFound();
        }
        self::assertNull($event->refresh()->read_at);
        self::assertSame('visible', $event->visibility);
    }

    #[Test]
    public function ninety_day_expiry_redacts_snapshot_and_keeps_active_action(): void
    {
        $user = User::factory()->create();
        $old = $this->event($user, 'credit_card_statement', 999999, 'statement_due_today');
        $old->forceFill(['created_at' => now()->subDays(100), 'resolved_at' => now()->subDays(91)])->save();
        $recentlyResolved = $this->event($user, 'credit_card_statement', 999999, 'statement_due_today');
        $recentlyResolved->forceFill(['created_at' => now()->subDays(100), 'resolved_at' => now()->subDays(5)])->save();
        $active = $this->event($user, 'credit_card_statement', 999999, 'statement_due_today');
        $active->forceFill(['created_at' => now()->subDays(100)])->save();

        self::assertSame(1, app(NotificationReconciler::class)->expireResolved());
        self::assertSame('expired', $old->refresh()->visibility);
        self::assertNull($old->event_snapshot);
        self::assertNull($old->current_context);
        self::assertSame('visible', $recentlyResolved->refresh()->visibility);
        self::assertSame('visible', $active->refresh()->visibility);

        $this->signIn($user);
        $ids = array_column($this->getJson('/api/v1/notifications')->assertOk()->json('data'), 'id');
        self::assertNotContains($old->id, $ids);
        self::assertContains($recentlyResolved->id, $ids);
        self::assertContains($active->id, $ids);
    }

    private function event(User $user, string $sourceKind, int $sourceId, string $type): NotificationEvent
    {
        return NotificationEvent::query()->create([
            'user_id' => $user->id,
            'event_key' => 'privacy-'.uniqid('', true),
            'type' => $type,
            'category' => $type === 'goal_reached' ? 'financial_goals' : 'credit_cards',
            'severity' => 'attention',
            'source_kind' => $sourceKind,
            'source_id' => $sourceId,
            'event_at' => now(),
            'visibility' => 'visible',
            'event_snapshot' => [
                'title' => ['pt-BR' => '<b>Safe</b> title<script>alert(1)</script>', 'en' => 'Safe title'],
                'summary' => ['pt-BR' => '<i>Safe</i> summary', 'en' => 'Safe summary'],
            ],
            'current_context' => ['summary' => ['pt-BR' => '<b>Safe</b> summary', 'en' => 'Safe summary']],
        ]);
    }

    private function signIn(User $user): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }
}
