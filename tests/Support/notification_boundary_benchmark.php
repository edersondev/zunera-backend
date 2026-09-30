<?php

declare(strict_types=1);

use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\User;
use App\Services\Notifications\StatementNotificationProjector;
use App\Services\RecurringTransactions\RecurringDateRange;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$user = User::factory()->create();
$client = new Client([
    'base_uri' => 'http://127.0.0.1', 'cookies' => new CookieJar, 'http_errors' => false,
    'headers' => ['Accept' => 'application/json', 'Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173'],
]);
$stamp = static fn (float $time): string => DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $time))
    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
try {
    $client->get('/sanctum/csrf-cookie');
    $token = null;
    foreach ($client->getConfig('cookies') as $cookie) {
        if ($cookie->getName() === 'XSRF-TOKEN') {
            $token = urldecode($cookie->getValue());
        }
    }
    $login = $client->post('/api/v1/auth/login', [
        'json' => ['email' => $user->email, 'password' => 'password'],
        'headers' => ['X-XSRF-TOKEN' => $token],
    ]);
    if ($login->getStatusCode() !== 200) {
        throw new RuntimeException('Login failed: '.$login->getStatusCode());
    }

    $today = CarbonImmutable::parse(RecurringDateRange::businessDate(), RecurringDateRange::BUSINESS_TIMEZONE);
    $tomorrow = $today->addDay();
    $card = CreditCard::factory()->withUser($user)->create();
    $due = CreditCardStatement::factory()->forCard($card)->closed()
        ->closing($today->subDays(5)->toDateString(), $tomorrow->toDateString())
        ->create(['original_amount_centavos' => 10000]);
    $overdue = CreditCardStatement::factory()->forCard($card)->closed()
        ->closing($today->subDays(6)->toDateString(), $today->toDateString())
        ->create(['original_amount_centavos' => 10000]);
    $start = microtime(true);
    CarbonImmutable::setTestNow($tomorrow->addMinute());
    Cache::forget('notifications.statement_scan_cursor');
    try {
        app(StatementNotificationProjector::class)->scanDateCandidates();
    } finally {
        CarbonImmutable::setTestNow();
    }
    $found = [];
    $deadline = microtime(true) + 30;
    do {
        $response = $client->get('/api/v1/notifications?view=all&limit=50');
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Notification list failed: '.$response->getStatusCode());
        }
        foreach (json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['data'] as $event) {
            $id = $event['destination']['params']['statement_id'] ?? null;
            if ($id === (int) $due->id && $event['type'] === 'statement_due_today' && ! isset($found['due_today'])) {
                $found['due_today'] = microtime(true);
            }
            if ($id === (int) $overdue->id && $event['type'] === 'statement_overdue' && ! isset($found['overdue'])) {
                $found['overdue'] = microtime(true);
            }
        }
        if (count($found) === 2) {
            break;
        }
        usleep(250000);
    } while (microtime(true) < $deadline);
    if (count($found) !== 2) {
        throw new RuntimeException('Boundary stages not visible: '.json_encode($found));
    }
    foreach (['due_today' => $due, 'overdue' => $overdue] as $case => $statement) {
        echo json_encode([
            'case' => 'simulated-boundary-'.$case,
            'source_id' => $statement->id,
            'simulated_business_boundary' => $tomorrow->toIso8601String(),
            'boundary_trigger_utc' => $stamp($start),
            'first_authorized_response_utc' => $stamp($found[$case]),
            'latency_seconds' => round($found[$case] - $start, 3),
        ], JSON_THROW_ON_ERROR).PHP_EOL;
    }
} finally {
    CarbonImmutable::setTestNow();
    CreditCardStatement::query()->where('user_id', $user->id)->delete();
    CreditCard::query()->where('user_id', $user->id)->delete();
    $user->delete();
}
