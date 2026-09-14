<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class AuthorizationAndIdempotencyTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function cross_user_access_is_denied_without_disclosing_data(): void
    {
        $owner = User::factory()->create();
        $rule = $this->rule($owner, ['description' => 'Aluguel confidencial']);

        $this->signInUser();

        $this->getJson('/api/v1/recurring-transactions/'.$rule->id)->assertNotFound();
        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, ['amount_centavos' => 1], ['Idempotency-Key' => 'x-1'])->assertNotFound();
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/pause', [], ['Idempotency-Key' => 'x-2'])->assertNotFound();
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/resume', [], ['Idempotency-Key' => 'x-3'])->assertNotFound();
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/end', [], ['Idempotency-Key' => 'x-4'])->assertNotFound();
        $this->getJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences')->assertNotFound();

        $list = $this->getJson('/api/v1/recurring-transactions')->assertOk();
        self::assertSame(0, $list->json('meta.total'));
    }

    #[Test]
    public function guest_access_is_rejected_on_every_recurrence_route(): void
    {
        $user = User::factory()->create();
        $rule = $this->rule($user);

        $this->getJson('/api/v1/recurring-transactions')->assertUnauthorized();
        $this->postJson('/api/v1/recurring-transactions', [], ['Idempotency-Key' => 'g-1'])->assertUnauthorized();
        $this->getJson('/api/v1/recurring-transactions/'.$rule->id)->assertUnauthorized();
        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [], ['Idempotency-Key' => 'g-2'])->assertUnauthorized();
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/pause', [], ['Idempotency-Key' => 'g-3'])->assertUnauthorized();
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/resume', [], ['Idempotency-Key' => 'g-4'])->assertUnauthorized();
        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/end', [], ['Idempotency-Key' => 'g-5'])->assertUnauthorized();
        $this->getJson('/api/v1/recurring-transactions/'.$rule->id.'/occurrences')->assertUnauthorized();
    }

    #[Test]
    public function creating_with_a_reused_key_and_changed_payload_conflicts(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $payload = $this->payload($account, $category);

        $this->postJson('/api/v1/recurring-transactions', $payload, ['Idempotency-Key' => 'create-key'])->assertCreated();
        $this->postJson('/api/v1/recurring-transactions', array_merge($payload, ['amount_centavos' => 999_999]), ['Idempotency-Key' => 'create-key'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_key_reused');

        self::assertSame(1, RecurringTransaction::query()->count());
        self::assertSame(25_000, RecurringTransaction::query()->sole()->amount_centavos);
    }
}
