<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialReports;

use App\Models\CreditCardStatementPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialReports\FinancialReportFixtures;
use Tests\TestCase;

final class FinancialReportAccountActivityTest extends TestCase
{
    use FinancialReportFixtures;
    use RefreshDatabase;

    #[Test]
    public function direct_flow_transfer_directions_and_settlement_have_distinct_figures(): void
    {
        $user = $this->reportSignIn();
        $a = $this->reportAccount($user, ['name' => 'A', 'initial_balance_centavos' => 200_000, 'current_balance_centavos' => 200_000]);
        $b = $this->reportAccount($user, ['name' => 'B']);
        $income = $this->reportCategory($user, 'income');
        $expense = $this->reportCategory($user);
        $this->reportTransaction($user, $a, $income, 100_000, '2026-09-11');
        $this->reportTransaction($user, $a, $expense, 20_000, '2026-09-12');
        $this->reportTransfer($user, $a, $b, 30_000, '2026-09-20');
        $this->reportTransfer($user, $a, $b, 5_000, '2026-09-20', ['status' => 'pending']);
        $purchase = $this->reportCardPurchase($user, $expense, 10_000, 1, '2026-09-05');
        $statement = $purchase->installments()->firstOrFail()->statement;
        $this->payStatement($statement, $a, 10_000, '2026-09-17');
        CreditCardStatementPayment::factory()->pending()->create([
            'user_id' => $user->id,
            'credit_card_id' => $purchase->credit_card_id,
            'credit_card_statement_id' => $statement->id,
            'financial_account_id' => $a->id,
            'amount_centavos' => 4_000,
            'payment_date' => '2026-09-18',
        ]);

        $data = $this->getJson('/api/v1/financial-reports')->assertOk()->json('data');
        $accounts = collect($data['accounts'])->keyBy('account.id');
        self::assertSame(100_000, $accounts[$a->id]['realized_income']['amount_centavos']);
        self::assertSame(20_000, $accounts[$a->id]['direct_expenses']['amount_centavos']);
        self::assertSame(80_000, $accounts[$a->id]['net_financial_flow']['amount_centavos']);
        self::assertSame(30_000, $accounts[$a->id]['transfer_out']['amount_centavos']);
        self::assertSame(0, $accounts[$a->id]['transfer_in']['amount_centavos']);
        self::assertSame(10_000, $accounts[$a->id]['card_settlement']['amount_centavos']);
        self::assertSame(30_000, $accounts[$b->id]['transfer_in']['amount_centavos']);
        self::assertSame(10_000, $data['unattributed_card_expenses']['amount_centavos']);
        self::assertSame(30_000, $data['summary']['realized_expenses']['amount_centavos']);
    }

    #[Test]
    public function account_scope_keeps_movements_but_type_or_category_filter_suppresses_them(): void
    {
        $user = $this->reportSignIn();
        $a = $this->reportAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);
        $b = $this->reportAccount($user);
        $expense = $this->reportCategory($user);
        $this->reportTransaction($user, $a, $expense, 2_000, '2026-09-12');
        $this->reportTransfer($user, $a, $b, 3_000, '2026-09-20');
        $purchase = $this->reportCardPurchase($user, $expense, 1_000, 1, '2026-09-05');
        $this->payStatement($purchase->installments()->firstOrFail()->statement, $a, 1_000, '2026-09-17');

        $data = $this->getJson('/api/v1/financial-reports?account_id='.$a->id)->assertOk()->json('data');
        self::assertSame(2_000, $data['summary']['realized_expenses']['amount_centavos']);
        self::assertSame(0, $data['unattributed_card_expenses']['amount_centavos']);
        self::assertSame(3_000, $data['accounts'][0]['transfer_out']['amount_centavos']);
        self::assertSame(1_000, $data['accounts'][0]['card_settlement']['amount_centavos']);

        foreach (['transaction_type=expense', 'category_id='.$expense->id] as $filter) {
            $filtered = $this->getJson('/api/v1/financial-reports?account_id='.$a->id.'&'.$filter)->assertOk()->json('data');
            self::assertSame(2_000, $filtered['summary']['realized_expenses']['amount_centavos']);
            self::assertSame(0, $filtered['accounts'][0]['transfer_out']['amount_centavos']);
            self::assertSame(0, $filtered['accounts'][0]['card_settlement']['amount_centavos']);
        }
    }
}
