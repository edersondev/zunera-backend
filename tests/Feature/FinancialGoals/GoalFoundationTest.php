<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialGoals;

use App\Models\FinancialAccount;
use App\Models\FinancialGoal;
use App\Models\FinancialGoalActivity;
use App\Models\FinancialGoalMutationRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_has_owner_exact_centavos_and_account_cannot_cascade_delete(): void
    {
        $owner = User::factory()->create();
        $account = FinancialAccount::factory()->for($owner)->create();
        $goal = FinancialGoal::create([
            'user_id' => $owner->id,
            'name' => 'Home',
            'target_centavos' => 999999999999,
            'financial_account_id' => $account->id,
            'account_name_snapshot' => $account->name,
        ]);

        $this->assertSame($owner->id, $goal->user->id);
        $this->assertSame($account->id, $goal->financialAccount->id);
        $this->assertSame(999999999999, $goal->fresh()->target_centavos);
        $this->assertFalse($goal->getAttributes()['target_centavos'] === 999999999999.0);

        try {
            $account->delete();
            $this->fail('Linked account deletion should be restricted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('financial_goals', ['id' => $goal->id]);
        }
    }

    public function test_activities_are_owned_and_stably_ordered(): void
    {
        $owner = User::factory()->create();
        $goal = FinancialGoal::create(['user_id' => $owner->id, 'name' => 'Trip', 'target_centavos' => 10000]);
        $at = now();
        foreach (['created', 'allocated'] as $type) {
            FinancialGoalActivity::create([
                'financial_goal_id' => $goal->id,
                'user_id' => $owner->id,
                'type' => $type,
                'amount_centavos' => $type === 'allocated' ? 125 : null,
                'occurred_at' => $at,
                'business_date' => $at->copy()->timezone('America/Sao_Paulo')->toDateString(),
            ]);
        }

        $this->assertSame(['allocated', 'created'], $goal->activities()->orderByDesc('occurred_at')->orderByDesc('id')->pluck('type')->all());
        $this->assertSame(125, $goal->activities()->where('type', 'allocated')->firstOrFail()->amount_centavos);
    }

    public function test_mutation_key_is_unique_per_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $attributes = ['idempotency_key' => 'same-key', 'operation' => 'create', 'request_fingerprint' => str_repeat('a', 64)];
        FinancialGoalMutationRequest::create(['user_id' => $owner->id, ...$attributes]);
        FinancialGoalMutationRequest::create(['user_id' => $other->id, ...$attributes]);

        $this->expectException(QueryException::class);
        FinancialGoalMutationRequest::create(['user_id' => $owner->id, ...$attributes]);
    }
}
