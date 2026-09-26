<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialGoals;

use App\Models\FinancialAccount;
use App\Models\FinancialGoalActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalMoneyActionContractTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_allocate_withdraw_replay_and_activity_history(): void
    {
        $owner = User::factory()->create();
        $account = FinancialAccount::factory()->for($owner)->create(['current_balance_centavos' => 1_000_000]);
        $this->login($owner);
        $id = $this->postJson('/api/v1/financial-goals', ['name' => 'Trip', 'target_centavos' => 500_000, 'financial_account_id' => $account->id, 'initial_allocated_centavos' => 500_000], ['Idempotency-Key' => 'create-trip'])->assertCreated()->json('data.id');
        $add = $this->postJson("/api/v1/financial-goals/{$id}/allocations", ['amount_centavos' => 50_000], ['Idempotency-Key' => 'add'])
            ->assertOk()->assertJsonPath('data.allocated_centavos', 550_000);
        $this->postJson("/api/v1/financial-goals/{$id}/allocations", ['amount_centavos' => 50_000], ['Idempotency-Key' => 'add'])->assertOk()->assertExactJson($add->json());
        $this->postJson("/api/v1/financial-goals/{$id}/allocations", ['amount_centavos' => 1], ['Idempotency-Key' => 'add'])->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
        $take = $this->postJson("/api/v1/financial-goals/{$id}/withdrawals", ['amount_centavos' => 20_000], ['Idempotency-Key' => 'take'])
            ->assertOk()->assertJsonPath('data.allocated_centavos', 530_000);
        $this->postJson("/api/v1/financial-goals/{$id}/withdrawals", ['amount_centavos' => 20_000], ['Idempotency-Key' => 'take'])->assertOk()->assertExactJson($take->json());
        $this->getJson("/api/v1/financial-goals/{$id}/activities")
            ->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.0.type', 'withdrawn')
            ->assertJsonPath('data.0.amount_centavos', 20_000)
            ->assertJsonPath('data.0.account_at_time.id', $account->id);
        $this->assertSame(1_000_000, $account->fresh()->current_balance_centavos);
        $this->assertSame(4, FinancialGoalActivity::count());
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_rejects_bad_amounts_underflow_and_foreign_goal(): void
    {
        $owner = User::factory()->create();
        $this->login($owner);
        $id = $this->postJson('/api/v1/financial-goals', ['name' => 'Trip', 'target_centavos' => 10_000], ['Idempotency-Key' => 'create-trip'])->assertCreated()->json('data.id');
        foreach ([0, -1, '1.5', 1_000_000_000_000] as $i => $amount) {
            $this->postJson("/api/v1/financial-goals/{$id}/allocations", ['amount_centavos' => $amount], ['Idempotency-Key' => "bad-{$i}"])->assertUnprocessable()->assertJsonValidationErrors('amount_centavos');
        }
        $this->postJson("/api/v1/financial-goals/{$id}/withdrawals", ['amount_centavos' => 1], ['Idempotency-Key' => 'underflow'])
            ->assertStatus(409)->assertJsonPath('code', 'goal_withdrawal_exceeds_allocation');
        $this->postJson("/api/v1/financial-goals/{$id}/allocations", ['amount_centavos' => 1])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->postJson("/api/v1/financial-goals/{$id}/allocations", ['amount_centavos' => 1], ['Idempotency-Key' => 'foreign'])->assertNotFound();
        $this->getJson("/api/v1/financial-goals/{$id}/activities")->assertNotFound();
    }
}
