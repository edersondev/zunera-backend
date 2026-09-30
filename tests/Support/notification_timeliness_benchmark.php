<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\FinancialAccount;
use App\Models\FinancialGoal;
use App\Models\FinancialGoalActivity;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringTransactions\RecurringDateRange;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::factory()->create();
$client = new Client([
    'base_uri' => 'http://127.0.0.1', 'cookies' => new CookieJar, 'http_errors' => false, 'timeout' => 10,
    'headers' => ['Accept' => 'application/json', 'Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173'],
]);
$stamp = static fn (float $time): string => DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $time))
    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
$xsrf = static function () use ($client): ?string {
    foreach ($client->getConfig('cookies') as $cookie) {
        if ($cookie->getName() === 'XSRF-TOKEN') {
            return urldecode($cookie->getValue());
        }
    }

    return null;
};
$post = static function (string $uri, array $data, string $key) use ($client, $xsrf): array {
    $response = $client->post($uri, ['json' => $data, 'headers' => ['X-XSRF-TOKEN' => $xsrf(), 'Idempotency-Key' => $key]]);
    $body = json_decode((string) $response->getBody(), true);
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
        throw new RuntimeException($uri.' returned '.$response->getStatusCode().': '.json_encode($body));
    }

    return $body;
};
$firstVisible = static function (callable $matches) use ($client): float {
    $deadline = microtime(true) + 120;
    do {
        $response = $client->get('/api/v1/notifications?view=all&limit=50');
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Notification list returned '.$response->getStatusCode());
        }
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($body['data'] as $event) {
            if ($matches($event)) {
                return microtime(true);
            }
        }
        usleep(250000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Notification did not appear within 120 seconds.');
};
$rows = [];
$record = static function (string $case, string $kind, int $sourceId, float $start, float $visible) use (&$rows, $stamp): void {
    $rows[] = [
        'case' => $case, 'kind' => $kind, 'source_id' => $sourceId,
        'start_utc' => $stamp($start), 'first_authorized_response_utc' => $stamp($visible),
        'latency_seconds' => round($visible - $start, 3),
    ];
};

try {
    $client->get('/sanctum/csrf-cookie');
    $login = $post('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'], 'benchmark-login');
    if (! isset($login['data'])) {
        throw new RuntimeException('Login did not return a session.');
    }

    for ($index = 1; $index <= 17; $index++) {
        $start = microtime(true);
        $goal = $post('/api/v1/financial-goals', [
            'name' => 'Notification benchmark '.$index, 'target_centavos' => 100, 'initial_allocated_centavos' => 100,
        ], 'notification-benchmark-goal-'.$index);
        $id = (int) $goal['data']['id'];
        $visible = $firstVisible(static fn (array $event): bool => ($event['destination']['params']['goal_id'] ?? null) === $id);
        $record('goal-'.$index, 'accepted_goal_create', $id, $start, $visible);
    }

    $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    $today = RecurringDateRange::businessDate();
    $rule = RecurringTransaction::factory()->withUser($user)->create([
        'financial_account_id' => $account->id, 'category_id' => $category->id,
        'start_date' => $today, 'eligibility_starts_on' => $today, 'schedule_cursor' => $today,
    ]);
    $start = microtime(true);
    if (Artisan::call('recurring:process-due', ['--date' => $today]) !== 0) {
        throw new RuntimeException('Recurrence processor failed.');
    }
    $occurrence = Transaction::query()->where('user_id', $user->id)->where('recurring_transaction_id', $rule->id)->firstOrFail();
    $visible = $firstVisible(static fn (array $event): bool => ($event['destination']['params']['transaction_id'] ?? null) === (int) $occurrence->id);
    $record('recurrence-processor', 'accepted_occurrence_generation', (int) $occurrence->id, $start, $visible);

    $card = CreditCard::factory()->withUser($user)->create();
    foreach (['statement-due-today' => $today, 'statement-overdue' => date('Y-m-d', strtotime($today.' -1 day'))] as $case => $due) {
        $statement = CreditCardStatement::factory()->forCard($card)->closed()
            ->closing(date('Y-m-d', strtotime($due.' -5 days')), $due)
            ->create(['original_amount_centavos' => 10000]);
        $start = microtime(true);
        $visible = $firstVisible(static fn (array $event): bool => ($event['destination']['params']['statement_id'] ?? null) === (int) $statement->id);
        $record($case, 'business_date_scan_proxy', (int) $statement->id, $start, $visible);
    }

    echo json_encode(['owner_id' => $user->id, 'scheduler' => 'live Docker schedule:work', 'rows' => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    Transaction::query()->where('user_id', $user->id)->delete();
    RecurringTransaction::query()->where('user_id', $user->id)->delete();
    CreditCardStatement::query()->where('user_id', $user->id)->delete();
    CreditCard::query()->where('user_id', $user->id)->delete();
    FinancialGoalActivity::query()->where('user_id', $user->id)->delete();
    FinancialGoal::query()->where('user_id', $user->id)->delete();
    FinancialAccount::query()->where('user_id', $user->id)->delete();
    Category::query()->where('user_id', $user->id)->delete();
    $user->delete();
}
