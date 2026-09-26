<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialGoals;

use App\Models\FinancialGoalActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalLifecycleContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_lifecycle_and_zero_allocation_archive_gate(): void
    {
        $owner = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertOk();
        $id = $this->postJson('/api/v1/financial-goals', ['name' => 'Trip', 'target_centavos' => 100, 'initial_allocated_centavos' => 100], ['Idempotency-Key' => 'create'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/financial-goals/{$id}/complete", [], ['Idempotency-Key' => 'complete'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson("/api/v1/financial-goals/{$id}/withdrawals", ['amount_centavos' => 1], ['Idempotency-Key' => 'blocked-money'])->assertStatus(409)->assertJsonPath('code', 'goal_completed_requires_reopen');
        $this->patchJson("/api/v1/financial-goals/{$id}", ['name' => 'Other'], ['Idempotency-Key' => 'blocked-patch'])->assertStatus(409);
        $this->postJson("/api/v1/financial-goals/{$id}/archive", [], ['Idempotency-Key' => 'blocked-archive'])->assertStatus(409);
        $this->postJson("/api/v1/financial-goals/{$id}/reopen", [], ['Idempotency-Key' => 'reopen'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/v1/financial-goals/{$id}/archive", [], ['Idempotency-Key' => 'funded-archive'])->assertStatus(409)->assertJsonPath('code', 'goal_archive_requires_zero_allocation');
        $this->postJson("/api/v1/financial-goals/{$id}/withdrawals", ['amount_centavos' => 100], ['Idempotency-Key' => 'take-all'])->assertOk();
        $this->postJson("/api/v1/financial-goals/{$id}/archive", [], ['Idempotency-Key' => 'archive'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->postJson("/api/v1/financial-goals/{$id}/allocations", ['amount_centavos' => 1], ['Idempotency-Key' => 'archived-money'])->assertStatus(409)->assertJsonPath('code', 'goal_archived_requires_restore');
        $this->postJson("/api/v1/financial-goals/{$id}/restore", [], ['Idempotency-Key' => 'restore'])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.allocated_centavos', 0);
        $this->getJson("/api/v1/financial-goals/{$id}/activities")->assertOk()->assertJsonCount(7, 'data');
        $this->assertSame(7, FinancialGoalActivity::count());
    }

    public function test_target_and_owner_are_enforced(): void
    {
        $owner = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertOk();
        $id = $this->postJson('/api/v1/financial-goals', ['name' => 'Trip', 'target_centavos' => 100], ['Idempotency-Key' => 'create'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/financial-goals/{$id}/complete", [], ['Idempotency-Key' => 'early'])->assertStatus(409)->assertJsonPath('code', 'goal_target_not_reached');
        $this->postJson("/api/v1/financial-goals/{$id}/reopen", [], ['Idempotency-Key' => 'wrong'])->assertStatus(409)->assertJsonPath('code', 'goal_invalid_transition');
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->postJson("/api/v1/financial-goals/{$id}/archive", [], ['Idempotency-Key' => 'foreign'])->assertNotFound();
    }
}
