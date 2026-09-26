<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialGoals;

use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalUpdateContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_change_preserves_old_event_snapshot_and_moves_designation_without_transfer(): void
    {
        $user = User::factory()->create();
        $old = FinancialAccount::factory()->for($user)->create(['current_balance_centavos' => 1000]);
        $new = FinancialAccount::factory()->for($user)->create(['current_balance_centavos' => 500]);
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $id = $this->postJson('/api/v1/financial-goals', ['name' => 'Home', 'target_centavos' => 1000, 'financial_account_id' => $old->id, 'initial_allocated_centavos' => 300], ['Idempotency-Key' => 'make'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/financial-goals/{$id}", ['name' => '  New home  ', 'financial_account_id' => $new->id], ['Idempotency-Key' => 'change'])
            ->assertOk()->assertJsonPath('data.name', 'New home')->assertJsonPath('data.financial_account.id', $new->id)
            ->assertJsonPath('data.financial_account.designated_centavos', 300);
        $this->getJson("/api/v1/financial-goals/{$id}/activities")
            ->assertOk()->assertJsonPath('data.0.type', 'account_changed')
            ->assertJsonPath('data.1.account_at_time.id', $old->id);
        $this->assertDatabaseCount('transfers', 0);
        $this->assertSame(1000, $old->fresh()->current_balance_centavos);
        $this->assertSame(500, $new->fresh()->current_balance_centavos);
        $this->patchJson("/api/v1/financial-goals/{$id}", ['financial_account_id' => null], ['Idempotency-Key' => 'unlink'])->assertOk()->assertJsonPath('data.account_backing', 'unverified');
    }

    public function test_patch_validation_capacity_and_owner(): void
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $id = $this->postJson('/api/v1/financial-goals', ['name' => 'X', 'target_centavos' => 100], ['Idempotency-Key' => 'make'])->assertCreated()->json('data.id');
        $small = FinancialAccount::factory()->for($user)->create(['current_balance_centavos' => 10]);
        $this->postJson("/api/v1/financial-goals/{$id}/allocations", ['amount_centavos' => 20], ['Idempotency-Key' => 'add'])->assertOk();
        $this->patchJson("/api/v1/financial-goals/{$id}", ['financial_account_id' => $small->id], ['Idempotency-Key' => 'too-small'])->assertStatus(409)->assertJsonPath('code', 'goal_account_capacity_exceeded');
        foreach ([['name' => '   '], ['name' => str_repeat('x', 201)], ['target_centavos' => 0], ['target_date' => '1900-01-01'], []] as $i => $payload) {
            $this->patchJson("/api/v1/financial-goals/{$id}", $payload, ['Idempotency-Key' => "bad-{$i}"])->assertUnprocessable();
        }
        $foreign = FinancialAccount::factory()->create();
        $this->patchJson("/api/v1/financial-goals/{$id}", ['financial_account_id' => $foreign->id], ['Idempotency-Key' => 'foreign'])->assertNotFound();
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->patchJson("/api/v1/financial-goals/{$id}", ['name' => 'No'], ['Idempotency-Key' => 'owner'])->assertNotFound();
    }
}
