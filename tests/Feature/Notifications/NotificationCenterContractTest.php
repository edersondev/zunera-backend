<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\NotificationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotificationCenterContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function routes_require_session_and_validate_list_query(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/notifications/summary')->assertUnauthorized();
        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/notifications?view=bad')->assertUnprocessable();
        $this->getJson('/api/v1/notifications?limit=51')->assertUnprocessable();
        $this->getJson('/api/v1/notifications?cursor=bad')->assertUnprocessable();
    }

    #[Test]
    public function list_and_summary_are_owner_scoped_and_localized_with_fallback(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $own = $this->seedEvent($owner, 1);
        $this->seedEvent($other, 2);
        $this->signIn($owner);

        $this->getJson('/api/v1/notifications/summary')
            ->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/notifications')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.title', 'Goal reached')
            ->assertJsonPath('data.0.origin', 'financial_goals');
        $this->withHeader('Accept-Language', 'fr')->getJson('/api/v1/notifications')
            ->assertOk()->assertJsonPath('data.0.title', 'Meta alcançada');
    }

    #[Test]
    public function open_and_read_change_only_read_state_and_recheck_destination(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $card = CreditCard::factory()->withUser($owner)->create();
        $statement = CreditCardStatement::factory()->forCard($card)->closed()->create();
        $event = $this->seedEvent($owner, 1, 'credit_card_statement', (int) $statement->id, 'statement_due_today');
        $foreign = $this->seedEvent($other, 2);
        $this->signIn($owner);

        $this->postJson("/api/v1/notifications/{$foreign->id}/open")->assertNotFound();
        $this->patchJson("/api/v1/notifications/{$event->id}/read")
            ->assertOk()->assertJsonPath('data.requires_action', true);
        $this->postJson("/api/v1/notifications/{$event->id}/open")
            ->assertOk()->assertJsonPath('data.destination.params.statement_id', $statement->id)
            ->assertJsonPath('data.requires_action', true);
        self::assertNotNull($event->refresh()->read_at);
        self::assertNull($event->resolved_at);
        self::assertSame(0, $statement->refresh()->paid_centavos);
    }

    #[Test]
    public function mark_all_reads_unseen_pages_without_resolving_actions(): void
    {
        $owner = User::factory()->create();
        $card = CreditCard::factory()->withUser($owner)->create();
        $statement = CreditCardStatement::factory()->forCard($card)->closed()->create();
        for ($index = 0; $index < 30; $index++) {
            $this->seedEvent($owner, $index, 'credit_card_statement', (int) $statement->id, 'statement_due_today');
        }
        $this->signIn($owner);
        $this->getJson('/api/v1/notifications?limit=10')->assertOk()->assertJsonCount(10, 'data');
        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()->assertJsonPath('data.changed_count', 30)
            ->assertJsonPath('data.summary.unread_count', 0)
            ->assertJsonPath('data.summary.requires_action_count', 30);
        self::assertSame(30, NotificationEvent::query()->whereNull('resolved_at')->count());
    }

    #[Test]
    public function lost_source_ownership_disappears_from_count_list_and_open(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $card = CreditCard::factory()->withUser($owner)->create();
        $statement = CreditCardStatement::factory()->forCard($card)->closed()->create();
        $event = $this->seedEvent($owner, 1, 'credit_card_statement', (int) $statement->id, 'statement_due_today');
        $this->signIn($owner);
        $this->getJson('/api/v1/notifications/summary')->assertJsonPath('data.unread_count', 1);

        $statement->user_id = $other->id;
        $statement->save();
        $this->getJson('/api/v1/notifications/summary')->assertJsonPath('data.unread_count', 0);
        $this->getJson('/api/v1/notifications')->assertJsonPath('data', []);
        $this->postJson("/api/v1/notifications/{$event->id}/open")->assertNotFound();
    }

    private function seedEvent(User $user, int $number, string $sourceKind = 'goal', int $sourceId = 999999, string $type = 'goal_reached'): NotificationEvent
    {
        return NotificationEvent::query()->create([
            'user_id' => $user->id,
            'event_key' => 'fixture-'.$user->id.'-'.$number,
            'type' => $type,
            'category' => $type === 'goal_reached' ? 'financial_goals' : 'credit_cards',
            'severity' => $type === 'goal_reached' ? 'success' : 'attention',
            'source_kind' => $sourceKind,
            'source_id' => $sourceId,
            'event_at' => now(),
            'visibility' => 'visible',
            'resolved_at' => $type === 'goal_reached' ? now() : null,
            'event_snapshot' => ['title' => ['pt-BR' => 'Meta alcançada', 'en' => 'Goal reached'], 'summary' => ['pt-BR' => 'Resumo.', 'en' => 'Summary.']],
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
