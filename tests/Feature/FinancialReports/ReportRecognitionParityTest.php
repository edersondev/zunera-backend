<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialReports;

use App\Services\CreditCards\CreditCardBudgetProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialReports\FinancialReportFixtures;
use Tests\TestCase;

final class ReportRecognitionParityTest extends TestCase
{
    use FinancialReportFixtures;
    use RefreshDatabase;

    #[Test]
    public function paid_refund_restates_dashboard_budget_and_history_in_original_period(): void
    {
        $user = $this->reportSignIn();
        $category = $this->reportCategory($user, 'expense', ['name' => 'Food']);
        $account = $this->reportAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);
        $purchase = $this->reportCardPurchase($user, $category, 10_000, 1, '2026-08-05');
        $event = $this->reportPaidRefund($purchase, $account, 2_000, '2026-08-17', '2026-09-25', 'parity-paid-refund');

        $period = '?preset=custom&from=2026-08-01&to=2026-08-31';
        $this->getJson('/api/v1/financial-dashboard/summary'.$period)->assertOk()
            ->assertJsonPath('data.realized_expenses.amount_centavos', 8_000);
        $this->getJson('/api/v1/financial-dashboard/expense-distribution'.$period)->assertOk()
            ->assertJsonPath('data.total_expenses.amount_centavos', 8_000)
            ->assertJsonPath('data.categories.0.total.amount_centavos', 8_000);
        $this->getJson('/api/v1/financial-dashboard/evolution?preset=custom&from=2026-08-10&to=2026-08-10')->assertOk()
            ->assertJsonPath('data.intervals.0.expenses.amount_centavos', 8_000);

        $budget = app(CreditCardBudgetProjectionService::class)
            ->realizedByCategory($user, '2026-08-01', '2026-08-31');
        self::assertSame(8_000, $budget->get($category->id));

        $entry = collect($this->getJson('/api/v1/financial-history?from=2026-08-01&to=2026-08-31')
            ->assertOk()->json('data'))->firstWhere('movement_kind', 'credit_card_expense');
        self::assertSame(8_000, $entry['amount_centavos']);
        self::assertSame($event->id, $entry['credit_events'][0]['id']);
        self::assertSame(2_000, $entry['credit_events'][0]['recognized_amount_centavos']);
        self::assertSame(90_000, $account->fresh()->current_balance_centavos);
    }

    #[Test]
    public function paid_multi_installment_refund_restates_each_original_month(): void
    {
        $user = $this->reportSignIn('2026-11-20');
        $category = $this->reportCategory($user);
        $account = $this->reportAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);
        $purchase = $this->reportCardPurchase($user, $category, 10_001, 3, '2026-08-05');
        foreach ($purchase->installments()->with('statement')->orderBy('sequence')->get() as $installment) {
            $this->payStatement($installment->statement, $account, $installment->amount_centavos, $installment->statement->due_date->toDateString());
        }
        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => 5_001,
            'event_date' => '2026-11-19',
        ], ['Idempotency-Key' => 'parity-multi'])->assertCreated();

        foreach ([
            ['2026-08-01', '2026-08-31', 0],
            ['2026-09-01', '2026-09-30', 1_667],
            ['2026-10-01', '2026-10-31', 3_333],
        ] as [$from, $to, $expected]) {
            $period = '?preset=custom&from='.$from.'&to='.$to;
            $this->getJson('/api/v1/financial-dashboard/summary'.$period)->assertOk()
                ->assertJsonPath('data.realized_expenses.amount_centavos', $expected);
            self::assertSame($expected, app(CreditCardBudgetProjectionService::class)
                ->realizedByCategory($user, $from, $to)->get($category->id, 0));
        }
    }
}
