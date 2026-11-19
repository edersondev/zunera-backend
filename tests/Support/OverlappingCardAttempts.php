<?php

declare(strict_types=1);

namespace Tests\Support;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

final class OverlappingCardAttempts
{
    /** @template T @param callable(string): T $run @return T */
    public static function inMySqlTestDatabase(callable $run): mixed
    {
        return $run(self::assertTestDatabase());
    }

    public static function assertTestDatabase(): string
    {
        $testingEnvironment = Dotenv::parse(file_get_contents(base_path('.env.testing')));
        $connection = DB::connection();

        if (! app()->environment('testing')
            || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== $testingEnvironment['DB_DATABASE']
            || $connection->getConfig('host') !== $testingEnvironment['DB_HOST']) {
            throw new RuntimeException('Card concurrency tests require the isolated MySQL test database.');
        }

        return $connection->getDatabaseName();
    }

    /**
     * Ten workers boot behind a barrier in each batch, then race on the same
     * database. Ten batches make 100 genuinely overlapping attempts.
     *
     * @return array{successes: int, failures: int}
     */
    public static function race(string $database, string $mode, int $userId, int $ruleId, int $occurrenceId = 0, int $alternateCardId = 0, int $alternateCategoryId = 0): array
    {
        $successes = 0;
        $failures = 0;
        $worker = base_path('tests/Support/overlapping_card_worker.php');

        for ($batch = 0; $batch < 10; $batch++) {
            $barrier = tempnam(sys_get_temp_dir(), 'card-barrier-');
            if ($barrier === false) {
                throw new RuntimeException('Could not create concurrency barrier.');
            }
            unlink($barrier);
            $processes = [];
            $readyPaths = [];

            try {
                for ($slot = 0; $slot < 10; $slot++) {
                    $index = $batch * 10 + $slot;
                    $ready = $barrier.'-'.$index;
                    $readyPaths[] = $ready;
                    $process = new Process([
                        PHP_BINARY, $worker, $database, $barrier, $ready, $mode,
                        (string) $userId, (string) $ruleId, (string) $occurrenceId,
                        (string) $alternateCardId, (string) $alternateCategoryId, (string) $index,
                    ]);
                    $process->setTimeout(30);
                    $process->start();
                    $processes[] = $process;
                }

                $deadline = microtime(true) + 20;
                while (count(array_filter($readyPaths, 'file_exists')) < count($readyPaths)) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Concurrency workers did not reach the start barrier.');
                    }
                    usleep(10_000);
                }
                touch($barrier);

                foreach ($processes as $process) {
                    $process->wait();
                    $process->isSuccessful() ? $successes++ : $failures++;
                }
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

        return ['successes' => $successes, 'failures' => $failures];
    }
}
