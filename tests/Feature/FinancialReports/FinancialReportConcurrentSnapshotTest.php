<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialReports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialReports\FinancialReportFixtures;
use Tests\TestCase;

/** MySQL regressions for a source write between two queries in one response. */
final class FinancialReportConcurrentSnapshotTest extends TestCase
{
    use DatabaseTruncation;
    use FinancialReportFixtures;

    #[Test]
    public function overview_sections_and_revision_share_one_source_state_during_a_write(): void
    {
        $this->requireMySql();
        $user = $this->reportSignIn();
        $account = $this->reportAccount($user);
        $category = $this->reportCategory($user);
        $transaction = $this->reportTransaction($user, $account, $category, 1_000, '2026-09-10');
        $writer = $this->writer();
        $changed = false;

        DB::listen(static function (QueryExecuted $query) use (&$changed, $writer, $transaction): void {
            if ($changed || ! str_contains($query->sql, 'contribution_count')) {
                return;
            }
            $changed = true;
            $writer->table('transactions')->where('id', $transaction->id)->update(['amount_centavos' => 2_000]);
        });

        $before = $this->getJson('/api/v1/financial-reports')->assertOk()->json('data');
        self::assertTrue($changed);
        self::assertSame(1_000, $before['summary']['realized_expenses']['amount_centavos']);
        self::assertSame(1_000, collect($before['evolution'])->sum('realized_expenses.amount_centavos'));
        self::assertSame(1_000, $before['expense_categories'][0]['total']['amount_centavos']);
        self::assertSame(1_000, $before['accounts'][0]['direct_expenses']['amount_centavos']);

        $after = $this->getJson('/api/v1/financial-reports')->assertOk()->json('data');
        self::assertSame(2_000, $after['summary']['realized_expenses']['amount_centavos']);
        self::assertNotSame($before['source_revision'], $after['source_revision']);
        DB::purge('report_snapshot_writer');
    }

    #[Test]
    public function detail_page_total_rows_and_revision_share_one_source_state_during_a_write(): void
    {
        $this->requireMySql();
        $user = $this->reportSignIn();
        $account = $this->reportAccount($user);
        $category = $this->reportCategory($user);
        $transaction = $this->reportTransaction($user, $account, $category, 1_000, '2026-09-10');
        $writer = $this->writer();
        $changed = false;

        DB::listen(static function (QueryExecuted $query) use (&$changed, $writer, $transaction): void {
            if ($changed || ! str_contains($query->sql, 'metric_amount_centavos') || ! str_contains(strtolower($query->sql), 'sum(')) {
                return;
            }
            $changed = true;
            $writer->table('transactions')->where('id', $transaction->id)->update(['amount_centavos' => 2_000]);
        });

        $url = '/api/v1/financial-reports/contributions?metric=realized_expenses';
        $before = $this->getJson($url)->assertOk()->json('data');
        self::assertTrue($changed);
        self::assertSame(1_000, $before['total']['amount_centavos']);
        self::assertSame(1_000, $before['contributions'][0]['signed_amount']['amount_centavos']);

        $after = $this->getJson($url)->assertOk()->json('data');
        self::assertSame(2_000, $after['total']['amount_centavos']);
        self::assertNotSame($before['source_revision'], $after['source_revision']);
        DB::purge('report_snapshot_writer');
    }

    private function requireMySql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            self::markTestSkipped('Concurrent source writes require the isolated MySQL test database.');
        }
    }

    private function writer(): ConnectionInterface
    {
        config(['database.connections.report_snapshot_writer' => config('database.connections.mysql')]);

        return DB::connection('report_snapshot_writer');
    }
}
