<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\FinancialGoal;
use App\Models\FinancialGoalActivity;
use App\Models\NotificationEvent;
use App\Models\NotificationProjectionFact;
use App\Models\User;
use App\Services\Notifications\GoalMilestoneProjector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class NotificationConcurrencyAndScaleTest extends TestCase
{
    #[Test]
    public function simultaneous_mysql_evaluators_create_one_event_and_process_one_fact(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('This verifies real MySQL concurrent evaluation.');
        }
        Artisan::call('migrate', ['--force' => true]);
        $user = User::factory()->create();
        try {
            $goal = FinancialGoal::query()->create([
                'user_id' => $user->id,
                'name' => 'Concurrent goal',
                'target_centavos' => 100,
                'status' => 'active',
            ]);
            FinancialGoalActivity::query()->create([
                'financial_goal_id' => $goal->id,
                'user_id' => $user->id,
                'type' => 'allocated',
                'amount_centavos' => 100,
                'occurred_at' => now(),
                'business_date' => now('America/Sao_Paulo')->toDateString(),
            ]);
            DB::transaction(fn () => app(GoalMilestoneProjector::class)->capture($goal));
            $factId = NotificationProjectionFact::query()->where('user_id', $user->id)->sole()->id;

            $this->raceEvaluators((int) $user->id);

            self::assertNotNull(NotificationProjectionFact::query()->findOrFail($factId)->processed_at);
            self::assertSame(1, NotificationEvent::query()->where('user_id', $user->id)->where('type', 'goal_reached')->count());
            self::assertSame(1, NotificationEvent::query()->where('user_id', $user->id)->distinct('event_key')->count('event_key'));
        } finally {
            $this->deleteOwner($user);
        }
    }

    #[Test]
    public function ten_thousand_mysql_items_keep_owner_counts_and_equal_time_cursor_fast(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('This measures the MySQL owner and cursor indexes.');
        }
        Artisan::call('migrate', ['--force' => true]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        try {
            $goal = FinancialGoal::query()->create([
                'user_id' => $owner->id, 'name' => 'Scale goal', 'target_centavos' => 100, 'status' => 'active',
            ]);
            $foreignGoal = FinancialGoal::query()->create([
                'user_id' => $other->id, 'name' => 'Other goal', 'target_centavos' => 100, 'status' => 'active',
            ]);
            $timestamp = now()->subMinute()->toDateTimeString();
            $snapshot = json_encode(['title' => ['pt-BR' => 'Meta', 'en' => 'Goal'], 'summary' => ['pt-BR' => 'Resumo', 'en' => 'Summary']], JSON_THROW_ON_ERROR);
            for ($offset = 0; $offset < 10000; $offset += 500) {
                $rows = [];
                for ($index = $offset; $index < $offset + 500; $index++) {
                    $rows[] = [
                        'user_id' => $owner->id,
                        'event_key' => 'scale-'.$owner->id.'-'.$index,
                        'type' => 'goal_reached',
                        'category' => 'financial_goals',
                        'severity' => 'success',
                        'source_kind' => 'goal',
                        'source_id' => $goal->id,
                        'event_at' => $timestamp,
                        'read_at' => $index % 2 === 0 ? $timestamp : null,
                        'resolved_at' => $timestamp,
                        'visibility' => 'visible',
                        'event_snapshot' => $snapshot,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }
                DB::table('notification_events')->insert($rows);
            }
            DB::table('notification_events')->insert([
                'user_id' => $other->id, 'event_key' => 'foreign-scale-'.$other->id,
                'type' => 'goal_reached', 'category' => 'financial_goals', 'severity' => 'success',
                'source_kind' => 'goal', 'source_id' => $foreignGoal->id, 'event_at' => $timestamp,
                'resolved_at' => $timestamp, 'visibility' => 'visible', 'event_snapshot' => $snapshot,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            $this->signIn($owner);
            $durations = [];
            $seen = [];
            $cursor = null;
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $started = microtime(true);
                $response = $this->getJson('/api/v1/notifications?view=all&limit=50'.($cursor === null ? '' : '&cursor='.urlencode($cursor)));
                $durations[] = microtime(true) - $started;
                $response->assertOk()->assertJsonCount(50, 'data');
                foreach ($response->json('data') as $item) {
                    self::assertSame($goal->id, $item['destination']['params']['goal_id']);
                    self::assertNotContains($item['id'], $seen);
                    $seen[] = $item['id'];
                }
                $cursor = $response->json('next_cursor');
                self::assertIsString($cursor);
            }
            self::assertCount(1000, $seen);
            self::assertGreaterThanOrEqual(19, count(array_filter($durations, static fn (float $duration): bool => $duration < 3.0)),
                'At least 95% of 10,000-item page requests must complete within three seconds.');
            $firstPageDurations = [];
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $view = $attempt % 2 === 0 ? 'all' : 'unread';
                $started = microtime(true);
                $this->getJson('/api/v1/notifications?view='.$view.'&limit=25')
                    ->assertOk()->assertJsonCount(25, 'data');
                $firstPageDurations[] = (microtime(true) - $started) * 1000;
            }
            $passing = count(array_filter($firstPageDurations, static fn (float $milliseconds): bool => $milliseconds < 3000));
            self::assertGreaterThanOrEqual(19, $passing, 'At least 95% of first-page/filter requests must complete within three seconds.');
            if (getenv('NOTIFICATION_SCALE_REPORT') === '1') {
                sort($firstPageDurations);
                fwrite(STDERR, "\nNOTIFICATION_SCALE_REPORT ".json_encode([
                    'attempts' => 20,
                    'passing' => $passing,
                    'p95_ms' => round($firstPageDurations[18], 1),
                    'max_ms' => round($firstPageDurations[19], 1),
                    'samples_ms' => array_map(static fn (float $value): float => round($value, 1), $firstPageDurations),
                ], JSON_THROW_ON_ERROR)."\n");
            }
            $this->getJson('/api/v1/notifications/summary')->assertOk()
                ->assertJsonPath('data.unread_count', 5000)
                ->assertJsonPath('data.requires_action_count', 0);
            $this->getJson('/api/v1/notifications?view=unread&limit=50')->assertOk()
                ->assertJsonCount(50, 'data');
            $this->getJson('/api/v1/notifications?view=requires_action')->assertOk()
                ->assertJsonPath('data', []);
        } finally {
            $this->deleteOwner($owner);
            $this->deleteOwner($other);
        }
    }

    private function raceEvaluators(int $userId): void
    {
        $barrier = tempnam(sys_get_temp_dir(), 'notif-eval-');
        self::assertIsString($barrier);
        unlink($barrier);
        $worker = base_path('tests/Support/notification_evaluation_worker.php');
        $ready = [];
        $processes = [];
        try {
            for ($index = 0; $index < 4; $index++) {
                $ready[$index] = $barrier.'-'.$index;
                $process = new Process([PHP_BINARY, $worker, $barrier, $ready[$index], (string) $userId]);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(array_filter($ready, 'file_exists')) !== 4) {
                if (microtime(true) >= $deadline) {
                    self::fail('Evaluators did not reach the barrier.');
                }
                usleep(1000);
            }
            touch($barrier);
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ([$barrier, ...$ready] as $path) {
                @unlink($path);
            }
        }
    }

    private function signIn(User $user): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }

    private function deleteOwner(User $user): void
    {
        FinancialGoalActivity::query()->where('user_id', $user->id)->delete();
        FinancialGoal::query()->where('user_id', $user->id)->delete();
        $user->delete();
    }
}
