<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialGoals;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalDateGuidanceContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_read_and_patch_show_calculated_guidance_without_recurring_activity(): void
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $date = now('America/Sao_Paulo')->addMonths(11)->toDateString();
        $id = $this->postJson('/api/v1/financial-goals', ['name' => 'Plan', 'target_centavos' => 1_200_000, 'target_date' => $date], ['Idempotency-Key' => 'create'])
            ->assertCreated()->assertJsonPath('data.target_date_state', 'future')->assertJsonPath('data.contribution_periods_remaining', 12)
            ->assertJsonPath('data.suggested_monthly_centavos', 100_000)->json('data.id');
        $this->getJson("/api/v1/financial-goals/{$id}")->assertOk()->assertJsonPath('data.suggested_monthly_centavos', 100_000);
        $this->patchJson("/api/v1/financial-goals/{$id}", ['target_date' => now('America/Sao_Paulo')->toDateString()], ['Idempotency-Key' => 'today'])
            ->assertOk()->assertJsonPath('data.target_date_state', 'due_today')->assertJsonPath('data.suggested_monthly_centavos', null);
        $this->assertDatabaseCount('recurring_transactions', 0);
        $this->assertDatabaseCount('transactions', 0);
    }
}
