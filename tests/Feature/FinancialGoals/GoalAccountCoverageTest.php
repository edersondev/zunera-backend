<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialGoals;

use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalAccountCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_goals_later_spending_shortfall_and_inactive_account(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create(['current_balance_centavos' => 100]);
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $ids = [];
        foreach ([30, 50] as $i => $amount) {
            $ids[] = $this->postJson('/api/v1/financial-goals', ['name' => "Goal {$i}", 'target_centavos' => 100, 'financial_account_id' => $account->id, 'initial_allocated_centavos' => $amount], ['Idempotency-Key' => "create-{$i}"])->assertCreated()->json('data.id');
        }
        $account->update(['current_balance_centavos' => 40]);
        $this->getJson("/api/v1/financial-goals/{$ids[0]}")->assertOk()
            ->assertJsonPath('data.account_backing', 'shortfall')
            ->assertJsonPath('data.financial_account.designated_centavos', 80)
            ->assertJsonPath('data.financial_account.unallocated_centavos', -40)
            ->assertJsonPath('data.financial_account.shortfall_centavos', 40);
        $this->postJson("/api/v1/financial-goals/{$ids[0]}/allocations", ['amount_centavos' => 1], ['Idempotency-Key' => 'blocked'])->assertStatus(409);
        $account->update(['status' => 'archived']);
        $this->getJson("/api/v1/financial-goals/{$ids[0]}")->assertOk()->assertJsonPath('data.account_backing', 'inactive_or_unavailable');
        $this->postJson("/api/v1/financial-goals/{$ids[0]}/withdrawals", ['amount_centavos' => 10], ['Idempotency-Key' => 'withdraw'])->assertOk();
        $this->postJson("/api/v1/financial-goals/{$ids[0]}/allocations", ['amount_centavos' => 1], ['Idempotency-Key' => 'inactive'])->assertStatus(409)->assertJsonPath('code', 'goal_account_unavailable');
    }
}
