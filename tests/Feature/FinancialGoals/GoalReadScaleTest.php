<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialGoals;

use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\FinancialGoals\FinancialGoalQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoalReadScaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_hundred_goals_and_one_thousand_events_have_bounded_read_queries(): void
    {
        $user = User::factory()->create();
        $accounts = FinancialAccount::factory()->count(5)->for($user)->create(['current_balance_centavos' => 1_000_000]);
        $now = now();
        $goals = [];
        for ($i = 0; $i < 100; $i++) {
            $account = $accounts[$i % 5];
            $goals[] = [
                'user_id' => $user->id,
                'name' => "Scale goal {$i}",
                'target_centavos' => 10_000,
                'financial_account_id' => $account->id,
                'account_name_snapshot' => $account->name,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('financial_goals')->insert($goals);
        $goalIds = DB::table('financial_goals')->where('user_id', $user->id)->pluck('id');
        $events = [];
        foreach ($goalIds as $goalId) {
            for ($i = 0; $i < 10; $i++) {
                $events[] = [
                    'financial_goal_id' => $goalId,
                    'user_id' => $user->id,
                    'type' => $i % 2 === 0 ? 'allocated' : 'withdrawn',
                    'amount_centavos' => $i % 2 === 0 ? 100 : 20,
                    'occurred_at' => $now,
                    'business_date' => $now->toDateString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($events, 250) as $chunk) {
            DB::table('financial_goal_activities')->insert($chunk);
        }

        $query = app(FinancialGoalQueryService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $summary = $query->summary($user->id);
        $summaryQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $page = $query->list($user->id, 'active', 50);
        $items = $page->getCollection()->map(fn ($goal) => $query->project($goal));
        $listQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $detail = $query->project($query->findOwned($user->id, (int) $goalIds[0]));
        $detailQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(100, $summary['active_count']);
        $this->assertSame(40_000, $summary['active_allocated_centavos']);
        $this->assertCount(50, $items);
        $this->assertSame(400, $detail['allocated_centavos']);
        $this->assertLessThanOrEqual(6, $summaryQueries, 'Summary read queries grew with goal count.');
        $this->assertLessThanOrEqual(6, $listQueries, 'List read queries grew with page size.');
        $this->assertLessThanOrEqual(4, $detailQueries, 'Detail read queries grew with event count.');
    }
}
