<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialGoals;

use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalFinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_mutations_do_not_write_any_financial_ledger_or_change_balance(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create(['current_balance_centavos' => 1000, 'initial_balance_centavos' => 1000]);
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $before = $this->getJson('/api/v1/financial-dashboard/summary')->assertOk()->json();
        $tables = ['transactions', 'transfers', 'monthly_budgets', 'credit_card_purchases', 'credit_card_statement_payments', 'recurring_transactions'];
        $id = $this->postJson('/api/v1/financial-goals', ['name' => 'Test', 'target_centavos' => 100, 'financial_account_id' => $account->id, 'initial_allocated_centavos' => 50], ['Idempotency-Key' => 'create'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/financial-goals/{$id}/allocations", ['amount_centavos' => 50], ['Idempotency-Key' => 'add'])->assertOk();
        $this->postJson("/api/v1/financial-goals/{$id}/complete", [], ['Idempotency-Key' => 'finish'])->assertOk();
        $this->assertSame(1000, $account->fresh()->current_balance_centavos);
        foreach ($tables as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($before, $this->getJson('/api/v1/financial-dashboard/summary')->assertOk()->json());
    }
}
