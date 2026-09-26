<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialGoals;

use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalCompletionBackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_shortfall_or_inactive_account_blocks_completion_but_unlink_allows_unverified(): void
    {
        $owner = User::factory()->create();
        $account = FinancialAccount::factory()->for($owner)->create(['current_balance_centavos' => 100]);
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertOk();
        $id = $this->postJson('/api/v1/financial-goals', ['name' => 'Trip', 'target_centavos' => 100, 'financial_account_id' => $account->id, 'initial_allocated_centavos' => 100], ['Idempotency-Key' => 'create'])->assertCreated()->json('data.id');
        $account->update(['current_balance_centavos' => 50]);
        $this->postJson("/api/v1/financial-goals/{$id}/complete", [], ['Idempotency-Key' => 'shortfall'])->assertStatus(409)->assertJsonPath('code', 'goal_account_shortfall');
        $account->update(['status' => 'archived']);
        $this->postJson("/api/v1/financial-goals/{$id}/complete", [], ['Idempotency-Key' => 'inactive'])->assertStatus(409)->assertJsonPath('code', 'goal_account_unavailable');
        $this->patchJson("/api/v1/financial-goals/{$id}", ['financial_account_id' => null], ['Idempotency-Key' => 'unlink'])->assertOk();
        $this->postJson("/api/v1/financial-goals/{$id}/complete", [], ['Idempotency-Key' => 'finish'])->assertOk()->assertJsonPath('data.account_backing', 'unverified')->assertJsonPath('data.status', 'completed');
    }

    public function test_completed_goal_keeps_designating_money_after_later_shortfall_and_archive(): void
    {
        $owner = User::factory()->create();
        $account = FinancialAccount::factory()->for($owner)->create(['current_balance_centavos' => 100]);
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertOk();
        $id = $this->postJson('/api/v1/financial-goals', ['name' => 'Trip', 'target_centavos' => 100, 'financial_account_id' => $account->id, 'initial_allocated_centavos' => 100], ['Idempotency-Key' => 'create'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/financial-goals/{$id}/complete", [], ['Idempotency-Key' => 'complete'])->assertOk();
        $account->update(['current_balance_centavos' => 40, 'status' => 'archived']);
        $this->getJson("/api/v1/financial-goals/{$id}")->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.account_backing', 'inactive_or_unavailable')->assertJsonPath('data.financial_account.designated_centavos', 100);
    }
}
