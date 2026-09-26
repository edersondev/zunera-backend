<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialGoals;

use App\Models\FinancialAccount;
use App\Models\FinancialGoal;
use App\Models\FinancialGoalActivity;
use App\Models\FinancialGoalMutationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalCreationContractTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_create_detail_list_and_replay_leave_actual_balance_unchanged(): void
    {
        $owner = User::factory()->create();
        $account = FinancialAccount::factory()->for($owner)->create(['current_balance_centavos' => 1_000_000]);
        $this->signIn($owner);
        $payload = ['name' => '  Home  ', 'target_centavos' => 3_000_000, 'financial_account_id' => $account->id, 'initial_allocated_centavos' => 300_000];
        $created = $this->postJson('/api/v1/financial-goals', $payload, ['Idempotency-Key' => 'create-home'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Home')
            ->assertJsonPath('data.allocated_centavos', 300_000)
            ->assertJsonPath('data.remaining_centavos', 2_700_000)
            ->assertJsonPath('data.progress_percentage', 10)
            ->assertJsonPath('data.financial_account.current_balance_centavos', 1_000_000)
            ->assertJsonPath('data.financial_account.designated_centavos', 300_000)
            ->assertJsonPath('data.financial_account.unallocated_centavos', 700_000);
        $goalId = $created->json('data.id');
        $this->postJson('/api/v1/financial-goals', $payload, ['Idempotency-Key' => 'create-home'])
            ->assertCreated()->assertExactJson($created->json());
        $this->postJson('/api/v1/financial-goals', [...$payload, 'target_centavos' => 4_000_000], ['Idempotency-Key' => 'create-home'])
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
        $this->getJson("/api/v1/financial-goals/{$goalId}")->assertOk()->assertJsonPath('data.id', $goalId);
        $this->getJson('/api/v1/financial-goals')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1_000_000, $account->fresh()->current_balance_centavos);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transfers', 0);
        $this->assertSame(2, FinancialGoalActivity::count());
        $this->assertSame(1, FinancialGoalMutationRequest::count());
    }

    public function test_unlinked_goal_and_owner_boundaries(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->postJson('/api/v1/financial-goals', ['name' => 'No auth', 'target_centavos' => 1], ['Idempotency-Key' => 'guest'])->assertUnauthorized();
        $this->signIn($owner);
        $created = $this->postJson('/api/v1/financial-goals', ['name' => 'Reserve', 'target_centavos' => 100], ['Idempotency-Key' => 'unlinked'])
            ->assertCreated()->assertJsonPath('data.account_backing', 'unverified')->assertJsonPath('data.allocated_centavos', 0);
        $id = $created->json('data.id');
        $this->actingAs($other);
        $this->getJson("/api/v1/financial-goals/{$id}")->assertNotFound();
        $this->getJson('/api/v1/financial-goals')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, FinancialGoal::count());
    }

    public function test_mutations_reject_fields_outside_the_published_contract(): void
    {
        $owner = User::factory()->create();
        $this->signIn($owner);
        $this->postJson('/api/v1/financial-goals', ['name' => 'Goal', 'target_centavos' => 100, 'surprise' => null], ['Idempotency-Key' => 'extra-create'])
            ->assertUnprocessable()->assertJsonValidationErrors('surprise');
        $id = $this->postJson('/api/v1/financial-goals', ['name' => 'Goal', 'target_centavos' => 100], ['Idempotency-Key' => 'valid-create'])
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/financial-goals/{$id}", ['name' => 'Changed', 'surprise' => 1], ['Idempotency-Key' => 'extra-update'])
            ->assertUnprocessable()->assertJsonValidationErrors('surprise');
        $this->postJson("/api/v1/financial-goals/{$id}/allocations", ['amount_centavos' => 1, 'surprise' => 1], ['Idempotency-Key' => 'extra-money'])
            ->assertUnprocessable()->assertJsonValidationErrors('surprise');
        $this->postJson("/api/v1/financial-goals/{$id}/complete", ['surprise' => 1], ['Idempotency-Key' => 'extra-lifecycle'])
            ->assertUnprocessable()->assertJsonValidationErrors('surprise');
        $this->assertSame('Goal', FinancialGoal::findOrFail($id)->name);
        $this->assertSame(1, FinancialGoalMutationRequest::count());
    }

    public function test_create_rejects_invalid_inputs_and_excess_capacity(): void
    {
        $owner = User::factory()->create();
        $account = FinancialAccount::factory()->for($owner)->create(['current_balance_centavos' => 100]);
        $foreign = FinancialAccount::factory()->create();
        $this->signIn($owner);
        foreach ([
            ['name' => '   ', 'target_centavos' => 100],
            ['name' => str_repeat('x', 201), 'target_centavos' => 100],
            ['name' => 'Goal', 'target_centavos' => 0],
            ['name' => 'Goal', 'target_centavos' => '1.2'],
            ['name' => 'Goal', 'target_centavos' => 1_000_000_000_000],
            ['name' => 'Goal', 'target_centavos' => 100, 'target_date' => '1900-01-01'],
            ['name' => 'Goal', 'target_centavos' => 100, 'initial_allocated_centavos' => -1],
        ] as $i => $payload) {
            $this->postJson('/api/v1/financial-goals', $payload, ['Idempotency-Key' => "bad-{$i}"])->assertUnprocessable();
        }
        $this->postJson('/api/v1/financial-goals', ['name' => 'Goal', 'target_centavos' => 100])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson('/api/v1/financial-goals', ['name' => 'Goal', 'target_centavos' => 100, 'financial_account_id' => $foreign->id], ['Idempotency-Key' => 'foreign'])->assertNotFound();
        $this->postJson('/api/v1/financial-goals', ['name' => 'Goal', 'target_centavos' => 1000, 'financial_account_id' => $account->id, 'initial_allocated_centavos' => 101], ['Idempotency-Key' => 'capacity'])
            ->assertStatus(409)->assertJsonPath('code', 'goal_account_capacity_exceeded');
        $this->assertDatabaseCount('financial_goals', 0);
    }
}
