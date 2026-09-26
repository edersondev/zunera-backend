<?php

declare(strict_types=1);

use App\Exceptions\FinancialGoals\FinancialGoalStateException;
use App\Services\FinancialGoals\FinancialGoalMutationService;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$environment = Dotenv::parse(file_get_contents(base_path('.env.testing')));
config(['database.connections.goal_mysql' => array_replace(config('database.connections.mysql'), ['database' => $environment['DB_DATABASE']])]);
DB::setDefaultConnection('goal_mysql');
[$script, $barrier, $ready, $userId, $goalId, $type, $amount, $key] = $argv;
touch($ready);
$deadline = microtime(true) + 20;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(10_000);
}
try {
    app(FinancialGoalMutationService::class)->moneyAction((int) $userId, (int) $goalId, $type, (int) $amount, $key);
    echo '200';
} catch (FinancialGoalStateException $exception) {
    echo '409';
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}
