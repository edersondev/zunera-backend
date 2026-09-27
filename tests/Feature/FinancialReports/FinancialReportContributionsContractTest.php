<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialReports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialReports\FinancialReportFixtures;
use Tests\TestCase;

final class FinancialReportContributionsContractTest extends TestCase
{
    use FinancialReportFixtures;
    use RefreshDatabase;

    #[Test]
    public function summary_and_category_metrics_reconcile_with_signed_current_and_prior_rows(): void
    {
        $user = $this->reportSignIn();
        $account = $this->reportAccount($user);
        $income = $this->reportCategory($user, 'income');
        $expense = $this->reportCategory($user);
        $this->reportTransaction($user, $account, $income, 10_000, '2026-09-10');
        $this->reportTransaction($user, $account, $expense, 3_000, '2026-09-11');
        $this->reportTransaction($user, $account, $expense, 2_000, '2026-08-11');

        $overview = $this->getJson('/api/v1/financial-reports')->assertOk()->json('data');
        $base = '/api/v1/financial-reports/contributions?';
        $result = $this->getJson($base.'metric=financial_result')->assertOk()->json('data');
        self::assertSame($overview['summary']['financial_result'], $result['total']);
        self::assertSame([10_000, -3_000], collect($result['contributions'])->pluck('signed_amount.amount_centavos')->sort()->reverse()->values()->all());
        self::assertSame('current', $result['which_period']);
        self::assertSame($overview['scope'], $result['scope']);

        $prior = $this->getJson($base.'metric=financial_result&which_period=previous')->assertOk()->json('data');
        self::assertSame(-2_000, $prior['total']['amount_centavos']);
        self::assertSame('2026-08-11', $prior['contributions'][0]['recognized_date']);

        $category = $this->getJson($base.'metric=expense_category&metric_id='.$expense->id)->assertOk()->json('data');
        self::assertSame(3_000, $category['total']['amount_centavos']);
        self::assertSame($expense->id, $category['metric_id']);
    }

    #[Test]
    public function paid_refund_has_source_link_and_every_cursor_page_sums_to_all_record_total(): void
    {
        $user = $this->reportSignIn();
        $account = $this->reportAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);
        $category = $this->reportCategory($user);
        $purchase = $this->reportCardPurchase($user, $category, 10_000, 1, '2026-08-05');
        $event = $this->reportPaidRefund($purchase, $account, 2_000, '2026-08-17', '2026-09-25', 'detail-paid-refund');
        $this->reportTransaction($user, $account, $category, 500, '2026-08-12');
        $overview = $this->getJson('/api/v1/financial-reports?preset=historical_month&month=2026-08')->assertOk()->json('data');
        $expected = $overview['expense_categories'][0]['total']['amount_centavos'];

        $query = ['preset' => 'historical_month', 'month' => '2026-08', 'metric' => 'expense_category', 'metric_id' => $category->id, 'limit' => 1];
        $seen = [];
        $sum = 0;
        $pages = 0;
        do {
            $data = $this->getJson('/api/v1/financial-reports/contributions?'.http_build_query($query))->assertOk()->json('data');
            self::assertSame($expected, $data['total']['amount_centavos']);
            self::assertCount(1, $data['contributions']);
            $row = $data['contributions'][0];
            $seen[] = $row;
            $sum += $row['signed_amount']['amount_centavos'];
            $query['cursor'] = $data['next_cursor'];
            $pages++;
        } while ($query['cursor'] !== null && $pages < 10);

        self::assertSame(3, $pages);
        self::assertSame($expected, $sum);
        $adjustment = collect($seen)->firstWhere('source_kind', 'card_credit_adjustment');
        self::assertSame(-2_000, $adjustment['signed_amount']['amount_centavos']);
        self::assertSame($event->id, $adjustment['related_credit_event_id']);
        self::assertSame($purchase->id, $adjustment['related_purchase_id']);
        self::assertSame('2026-08-10', $adjustment['recognized_date']);
    }

    #[Test]
    public function foreign_targets_invalid_metric_and_cursor_return_safe_errors(): void
    {
        $user = $this->reportSignIn();
        $other = User::factory()->create();
        $foreign = $this->reportCategory($other);
        $own = $this->reportCategory($user);
        $base = '/api/v1/financial-reports/contributions?';

        $this->getJson($base.'metric=expense_category&metric_id='.$foreign->id)->assertNotFound();
        $this->getJson($base.'metric=expense_category&metric_id='.$own->id.'&cursor=invalid')->assertUnprocessable();
        $this->getJson($base.'metric=nope')->assertUnprocessable();
        $this->getJson($base.'metric=expense_category')->assertUnprocessable();
        $this->getJson($base.'metric=realized_income&metric_id='.$own->id)->assertUnprocessable();
        $this->getJson($base.'metric=realized_income&limit=101')->assertUnprocessable();
    }

    #[Test]
    public function account_movement_detail_keeps_transfer_and_settlement_out_of_money_metrics(): void
    {
        $user = $this->reportSignIn();
        $a = $this->reportAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);
        $b = $this->reportAccount($user);
        $category = $this->reportCategory($user);
        $this->reportTransaction($user, $a, $category, 2_000, '2026-09-12');
        $this->reportTransfer($user, $a, $b, 3_000, '2026-09-20');
        $purchase = $this->reportCardPurchase($user, $category, 1_000, 1, '2026-09-05');
        $this->payStatement($purchase->installments()->firstOrFail()->statement, $a, 1_000, '2026-09-17');

        $base = '/api/v1/financial-reports/contributions?metric_id='.$a->id.'&metric=';
        $this->getJson($base.'account_expenses')->assertOk()->assertJsonPath('data.total.amount_centavos', 2_000);
        $this->getJson($base.'account_transfer_out')->assertOk()->assertJsonPath('data.total.amount_centavos', 3_000);
        $this->getJson($base.'account_card_settlement')->assertOk()->assertJsonPath('data.total.amount_centavos', 1_000);
        $this->getJson($base.'account_transfer_out&transaction_type=expense')->assertOk()
            ->assertJsonPath('data.total.amount_centavos', 0)->assertJsonPath('data.contributions', []);
    }
}
