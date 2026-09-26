<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialGoals;

use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalOverviewDashboardContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_counts_active_totals_unverified_and_independent_attention(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create(['current_balance_centavos' => 100]);
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $linked = $this->postJson('/api/v1/financial-goals', ['name' => 'Linked', 'target_centavos' => 100, 'financial_account_id' => $account->id, 'initial_allocated_centavos' => 70], ['Idempotency-Key' => 'linked'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/financial-goals', ['name' => 'Unlinked', 'target_centavos' => 100, 'initial_allocated_centavos' => 20], ['Idempotency-Key' => 'unlinked'])->assertCreated();
        $this->postJson('/api/v1/financial-goals', ['name' => 'Completed', 'target_centavos' => 20, 'financial_account_id' => $account->id, 'initial_allocated_centavos' => 20], ['Idempotency-Key' => 'completed'])->assertCreated();
        $completedId = $this->getJson('/api/v1/financial-goals?status=all')->assertOk()->json('data.0.id');
        $this->postJson("/api/v1/financial-goals/{$completedId}/complete", [], ['Idempotency-Key' => 'finish'])->assertOk();
        $account->update(['current_balance_centavos' => 50]);
        $this->getJson('/api/v1/financial-goals/summary')->assertOk()
            ->assertJsonPath('data.active_count', 2)
            ->assertJsonPath('data.completed_count', 1)
            ->assertJsonPath('data.active_target_centavos', 200)
            ->assertJsonPath('data.active_allocated_centavos', 90)
            ->assertJsonPath('data.active_remaining_centavos', 110)
            ->assertJsonPath('data.active_unverified_centavos', 20)
            ->assertJsonPath('data.attention_counts.shortfall_linked_goals', 2)
            ->assertJsonPath('data.linked_accounts.0.designated_centavos', 90);
        $this->getJson("/api/v1/financial-goals/{$linked}")->assertOk()->assertJsonPath('data.account_backing', 'shortfall');
        $account->update(['status' => 'archived']);
        $this->getJson('/api/v1/financial-goals/summary')->assertOk()
            ->assertJsonPath('data.attention_counts.shortfall_linked_goals', 2)
            ->assertJsonPath('data.attention_counts.inactive_or_unavailable_linked_goals', 2);
    }

    public function test_dashboard_is_bounded_and_sorted_and_reads_are_owner_scoped(): void
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        foreach ([['Undated', null], ['Later', now('America/Sao_Paulo')->addMonths(2)->toDateString()], ['Soon', now('America/Sao_Paulo')->addDays(2)->toDateString()], ['Tomorrow', now('America/Sao_Paulo')->addDay()->toDateString()]] as $i => [$name, $date]) {
            $this->postJson('/api/v1/financial-goals', ['name' => $name, 'target_centavos' => 100, 'target_date' => $date], ['Idempotency-Key' => "goal-{$i}"])->assertCreated();
        }
        $this->getJson('/api/v1/financial-dashboard/goals')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Tomorrow')->assertJsonPath('data.1.name', 'Soon')->assertJsonPath('data.2.name', 'Later');
        $this->getJson('/api/v1/financial-goals?per_page=2')->assertOk()->assertJsonCount(2, 'data');
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->getJson('/api/v1/financial-goals/summary')->assertOk()->assertJsonPath('data.active_count', 0);
        $this->getJson('/api/v1/financial-dashboard/goals')->assertOk()->assertJsonCount(0, 'data');
    }
}
