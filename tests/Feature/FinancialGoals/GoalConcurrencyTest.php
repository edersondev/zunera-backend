<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialGoals;

use App\Exceptions\FinancialGoals\FinancialGoalStateException;
use App\Models\FinancialAccount;
use App\Models\FinancialGoal;
use App\Models\User;
use App\Services\FinancialGoals\FinancialGoalCapacityService;
use App\Services\FinancialGoals\FinancialGoalMutationService;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GoalConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_account_capacity_and_distinct_withdrawals_are_serially_checked(): void
    {
        $owner = User::factory()->create();
        $account = FinancialAccount::factory()->for($owner)->create(['current_balance_centavos' => 100]);
        $first = FinancialGoal::create(['user_id' => $owner->id, 'name' => 'A', 'target_centavos' => 100, 'financial_account_id' => $account->id]);
        $second = FinancialGoal::create(['user_id' => $owner->id, 'name' => 'B', 'target_centavos' => 100, 'financial_account_id' => $account->id]);
        $service = app(FinancialGoalMutationService::class);
        $service->moneyAction($owner->id, $first->id, 'allocated', 70, 'a');
        $this->expectException(FinancialGoalStateException::class);
        $service->moneyAction($owner->id, $second->id, 'allocated', 40, 'b');
    }

    public function test_mysql_overlapping_shared_account_claims_and_withdrawals(): void
    {
        if (getenv('GOAL_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Run with GOAL_MYSQL_CONCURRENCY=1 against the development MySQL stack.');
        }
        $environment = Dotenv::parse(file_get_contents(base_path('.env.testing')));
        config(['database.connections.goal_mysql' => array_replace(config('database.connections.mysql'), ['database' => $environment['DB_DATABASE']])]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('goal_mysql');
        $owner = null;
        try {
            Artisan::call('migrate', ['--database' => 'goal_mysql', '--force' => true]);
            $owner = User::factory()->create();
            $account = FinancialAccount::factory()->for($owner)->create(['current_balance_centavos' => 100]);
            $first = FinancialGoal::create(['user_id' => $owner->id, 'name' => 'A', 'target_centavos' => 100, 'financial_account_id' => $account->id]);
            $second = FinancialGoal::create(['user_id' => $owner->id, 'name' => 'B', 'target_centavos' => 100, 'financial_account_id' => $account->id]);
            $this->raceMysql($owner->id, [$first->id, $second->id], 'allocated', 70);
            $total = app(FinancialGoalCapacityService::class)->designatedForAccount($owner->id, $account->id);
            self::assertSame(70, $total);

            $funded = app(FinancialGoalCapacityService::class)->allocationForGoal($first->id) > 0 ? $first : $second;
            $this->raceMysql($owner->id, [$funded->id, $funded->id], 'withdrawn', 50);
            self::assertSame(20, app(FinancialGoalCapacityService::class)->allocationForGoal($funded->id));
        } finally {
            if ($owner !== null) {
                DB::table('financial_goal_activities')->where('user_id', $owner->id)->delete();
                DB::table('financial_goal_mutation_requests')->where('user_id', $owner->id)->delete();
                DB::table('financial_goals')->where('user_id', $owner->id)->delete();
                DB::table('financial_accounts')->where('user_id', $owner->id)->delete();
                DB::table('users')->where('id', $owner->id)->delete();
            }
            DB::setDefaultConnection($original);
            DB::purge('goal_mysql');
        }
    }

    /** @param list<int> $goalIds */
    private function raceMysql(int $userId, array $goalIds, string $type, int $amount): void
    {
        $barrier = tempnam(sys_get_temp_dir(), 'goal-barrier-');
        unlink($barrier);
        $worker = base_path('tests/Support/mysql_goal_worker.php');
        $processes = [];
        $readyPaths = [];
        try {
            foreach ($goalIds as $i => $goalId) {
                $ready = $barrier.'-'.$i;
                $readyPaths[] = $ready;
                $process = new Process([PHP_BINARY, $worker, $barrier, $ready, (string) $userId, (string) $goalId, $type, (string) $amount, bin2hex(random_bytes(8))]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (count(array_filter($readyPaths, 'file_exists')) < 2) {
                if (microtime(true) > $deadline) {
                    self::fail('MySQL workers did not reach barrier.');
                }
                usleep(10_000);
            }
            touch($barrier);
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $statuses[] = (int) trim($process->getOutput());
            }
            sort($statuses);
            self::assertSame([200, 409], $statuses);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ($readyPaths as $ready) {
                @unlink($ready);
            }
            @unlink($barrier);
        }
    }
}
