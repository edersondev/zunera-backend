<?php

declare(strict_types=1);

use App\Services\Notifications\NotificationFactDispatcher;
use App\Services\Notifications\NotificationReconciler;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $barrier, $ready, $userId] = $argv;
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(1000);
}

$result = app(NotificationReconciler::class)->drain(app(NotificationFactDispatcher::class)->handle(...), userId: (int) $userId);
if ($result['failed'] > 0) {
    exit(3);
}
