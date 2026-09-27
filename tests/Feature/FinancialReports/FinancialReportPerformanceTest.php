<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialReports;

use App\Models\Category;
use App\Models\FinancialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialReports\FinancialReportFixtures;
use Tests\TestCase;

final class FinancialReportPerformanceTest extends TestCase
{
    use FinancialReportFixtures;
    use RefreshDatabase;

    #[Test]
    public function ten_thousand_records_hundred_categories_and_fifty_accounts_keep_totals_and_pages_bounded(): void
    {
        $user = $this->reportSignIn();
        $accounts = collect(range(1, 50))->map(fn (int $index): FinancialAccount => $this->reportAccount($user, ['name' => 'Account '.str_pad((string) $index, 2, '0', STR_PAD_LEFT)]));
        $categories = collect(range(1, 100))->map(fn (int $index): Category => $this->reportCategory($user, 'expense', ['name' => 'Category '.str_pad((string) $index, 3, '0', STR_PAD_LEFT)]));
        $now = CarbonImmutable::parse('2026-09-26 10:00:00', 'America/Sao_Paulo');
        $batch = [];
        for ($index = 0; $index < 10_000; $index++) {
            $batch[] = [
                'user_id' => $user->id,
                'financial_account_id' => $accounts[$index % 50]->id,
                'category_id' => $categories[$index % 100]->id,
                'type' => 'expense',
                'status' => 'effective',
                'description' => 'Report movement '.$index,
                'notes' => null,
                'amount_centavos' => 100,
                'currency_code' => 'BRL',
                'transaction_date' => '2026-09-'.str_pad((string) (($index % 26) + 1), 2, '0', STR_PAD_LEFT),
                'search_text' => 'report movement',
                'removed_at' => null,
                'recurring_transaction_id' => null,
                'recurrence_scheduled_date' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($batch) === 500) {
                DB::table('transactions')->insert($batch);
                $batch = [];
            }
        }

        $this->getJson('/api/v1/financial-reports')->assertOk(); // warm app and query paths
        $started = microtime(true);
        $data = $this->getJson('/api/v1/financial-reports')->assertOk()->json('data');
        $overviewSeconds = microtime(true) - $started;
        self::assertSame(1_000_000, $data['summary']['realized_expenses']['amount_centavos']);
        self::assertCount(100, $data['expense_categories']);
        self::assertCount(50, $data['accounts']);
        self::assertLessThan(5.0, $overviewSeconds, 'Overview took '.$overviewSeconds.' seconds.');

        $started = microtime(true);
        $first = $this->getJson('/api/v1/financial-reports/contributions?metric=realized_expenses&limit=100')->assertOk()->json('data');
        $detailSeconds = microtime(true) - $started;
        self::assertSame(1_000_000, $first['total']['amount_centavos']);
        self::assertCount(100, $first['contributions']);
        self::assertNotNull($first['next_cursor']);
        self::assertLessThan(5.0, $detailSeconds, 'First contribution page took '.$detailSeconds.' seconds.');

        $next = $this->getJson('/api/v1/financial-reports/contributions?metric=realized_expenses&limit=100&cursor='.urlencode($first['next_cursor']))->assertOk()->json('data');
        self::assertCount(100, $next['contributions']);
        self::assertNotSame($first['contributions'][0]['source_id'], $next['contributions'][0]['source_id']);

        $isSqlite = DB::getDriverName() === 'sqlite';
        $plan = DB::select(($isSqlite ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ').'SELECT id FROM transactions WHERE user_id = ? AND removed_at IS NULL AND transaction_date >= ? AND transaction_date <= ?', [$user->id, '2026-09-01', '2026-09-26']);
        $planText = json_encode($plan, JSON_THROW_ON_ERROR);
        self::assertStringContainsString($isSqlite ? 'INDEX' : 'transactions_owner_history_index', $planText);

        if (getenv('REPORT_PERF_LOG') === '1') {
            fwrite(STDOUT, sprintf("\nReport performance: driver=%s records=10000 categories=100 accounts=50 overview=%.3fs first_page=%.3fs indexed=yes\n", DB::getDriverName(), $overviewSeconds, $detailSeconds));
        }
    }
}
