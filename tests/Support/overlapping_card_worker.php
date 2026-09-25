<?php

declare(strict_types=1);

use App\Data\RecurringTransactions\ConfirmCardOccurrenceData;
use App\Models\RecurringCardOccurrence;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Services\RecurringTransactions\RecurringCardOccurrenceActionService;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $database, $barrier, $ready, $mode, $userId, $ruleId, $occurrenceId, $alternateCardId, $alternateCategoryId, $index] = $argv;
config(['database.connections.sqlite.database' => $database]);
DB::purge('sqlite');
DB::setDefaultConnection('sqlite');
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 20;
while (! file_exists($barrier)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, 'Start barrier timed out.');
        exit(2);
    }
    usleep(1_000);
}

try {
    if ($mode === 'generate') {
        app(RecurringOccurrenceService::class)->processRule((int) $ruleId, '2026-09-24');
    } else {
        $user = User::query()->findOrFail((int) $userId);
        $rule = RecurringTransaction::query()->findOrFail((int) $ruleId);
        $occurrence = RecurringCardOccurrence::query()->findOrFail((int) $occurrenceId);
        $actions = app(RecurringCardOccurrenceActionService::class);

        if ($mode === 'automatic') {
            match ((int) $index % 3) {
                0 => $actions->retry($user, $rule, $occurrence),
                1 => $actions->confirm($user, $rule, $occurrence, new ConfirmCardOccurrenceData(confirmOverLimit: true, expectedAvailableCreditCentavos: 1_000)),
                default => $actions->dismiss($user, $rule, $occurrence),
            };
        } elseif ($mode === 'confirmation') {
            $actions->confirm($user, $rule, $occurrence, new ConfirmCardOccurrenceData(
                actualAmountCentavos: 15_000 + (int) $index,
                actualPurchaseDate: sprintf('2026-09-%02d', 1 + ((int) $index % 20)),
                creditCardId: (int) $index % 2 === 0 ? null : (int) $alternateCardId,
                categoryId: (int) $index % 2 === 0 ? null : (int) $alternateCategoryId,
            ));
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}
