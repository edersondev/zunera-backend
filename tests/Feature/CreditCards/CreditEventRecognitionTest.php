<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\CreditCardCreditEvent;
use App\Services\CreditCards\RecognizedCardExpenseProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialReports\FinancialReportFixtures;
use Tests\TestCase;

final class CreditEventRecognitionTest extends TestCase
{
    use FinancialReportFixtures;
    use RefreshDatabase;

    #[Test]
    public function paid_source_refund_restates_recognized_expense_without_reversing_cash_payment(): void
    {
        $user = $this->reportSignIn();
        $category = $this->reportCategory($user);
        $account = $this->reportAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);
        $purchase = $this->reportCardPurchase($user, $category, 10_000, 1, '2026-08-05');
        $this->reportPaidRefund($purchase, $account, 2_000, '2026-08-17', '2026-09-25', 'paid-recognition');

        $row = $this->recognizedRows($user->id, '2026-08-01', '2026-08-31')->sole();
        self::assertSame(10_000, (int) $row->amount_centavos);
        self::assertSame(2_000, (int) $row->recognized_adjustment_centavos);
        self::assertSame(8_000, (int) $row->recognized_amount_centavos);
        self::assertSame(0, $purchase->installments()->firstOrFail()->fresh()->credit_adjustment_centavos);
        self::assertSame(90_000, $account->fresh()->current_balance_centavos);
        self::assertSame(2_000, $this->cardSummary($purchase->creditCard->fresh())['card_credit_centavos']);
    }

    #[Test]
    public function partly_paid_and_unpaid_source_use_same_purchase_allocation(): void
    {
        $user = $this->reportSignIn();
        $category = $this->reportCategory($user);
        $account = $this->reportAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);
        $partlyPaid = $this->reportCardPurchase($user, $category, 10_000, 1, '2026-08-05');
        $this->payStatement($partlyPaid->installments()->firstOrFail()->statement, $account, 4_000, '2026-08-17');
        $unpaid = $this->reportCardPurchase($user, $category, 10_000, 1, '2026-08-05');

        foreach ([$partlyPaid, $unpaid] as $index => $purchase) {
            $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
                'reason' => 'refund',
                'amount_centavos' => 3_000,
                'event_date' => '2026-09-25',
            ], ['Idempotency-Key' => 'part-unpaid-'.$index])->assertCreated();
        }

        $rows = $this->recognizedRows($user->id, '2026-08-01', '2026-08-31')->keyBy('purchase_id');
        self::assertSame(7_000, (int) $rows[$partlyPaid->id]->recognized_amount_centavos);
        self::assertSame(7_000, (int) $rows[$unpaid->id]->recognized_amount_centavos);
    }

    #[Test]
    public function multiple_installments_allocate_exact_centavos_in_purchase_sequence(): void
    {
        $user = $this->reportSignIn('2026-11-20');
        $purchase = $this->reportCardPurchase($user, $this->reportCategory($user), 10_001, 3, '2026-08-05');
        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'correction',
            'amount_centavos' => 5_001,
            'event_date' => '2026-11-19',
        ], ['Idempotency-Key' => 'multi-recognition'])->assertCreated();

        $rows = $this->recognizedRows($user->id, '2026-08-01', '2026-10-31')->sortBy('sequence')->values();
        self::assertSame([0, 1_667, 3_333], $rows->pluck('recognized_amount_centavos')->map(intval(...))->all());
        self::assertSame(5_000, $rows->sum('recognized_amount_centavos'));
    }

    #[Test]
    public function cancellation_and_idempotent_replay_reduce_recognition_once(): void
    {
        $user = $this->reportSignIn();
        $purchase = $this->reportCardPurchase($user, $this->reportCategory($user), 10_000, 1, '2026-08-05');
        $payload = ['reason' => 'cancellation', 'amount_centavos' => 10_000, 'event_date' => '2026-09-25'];
        $path = '/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events';
        $this->postJson($path, $payload, ['Idempotency-Key' => 'cancel-recognition'])->assertCreated();
        $this->postJson($path, $payload, ['Idempotency-Key' => 'cancel-recognition'])->assertCreated();

        self::assertSame(1, CreditCardCreditEvent::query()->where('credit_card_purchase_id', $purchase->id)->count());
        self::assertSame(0, (int) $this->recognizedRows($user->id, '2026-08-01', '2026-08-31')->sole()->recognized_amount_centavos);
    }

    private function recognizedRows(int $userId, string $from, string $to): Collection
    {
        return app(RecognizedCardExpenseProjection::class)->installments($userId, $from, $to, true)->get();
    }
}
