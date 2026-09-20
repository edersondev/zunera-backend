<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\CreditCardStatementPayment;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardStatementPaymentTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    /** @return array<string, array{0: int, 1: int, 2: string, 3: string, 4: string}> */
    public static function settlementCases(): array
    {
        $cases = [];
        foreach ([10_000, 33_300, 99_999, 1] as $outstanding) {
            foreach ([1, 2, 3, 10, 25, 50] as $divisor) {
                $amount = max(1, intdiv($outstanding, $divisor));
                $cases["{$amount} of {$outstanding}"] = [
                    $outstanding,
                    $amount,
                    $amount >= $outstanding ? 'paid' : 'overdue',
                    '2026-09-18',
                    '2026-09-18',
                ];
            }
        }

        foreach ([10, 100, 1_000, 10_000] as $outstanding) {
            foreach ([1, 2, 5, 9] as $chunk) {
                $amount = min($outstanding, $chunk);
                $cases["on-time chunk {$amount} of {$outstanding}"] = [
                    $outstanding,
                    $amount,
                    $amount >= $outstanding ? 'paid' : 'partially_paid',
                    '2026-08-16',
                    '2026-08-16',
                ];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('settlementCases')]
    public function effective_payments_move_account_and_statement_by_equal_amounts_once(int $outstanding, int $amount, string $expectedStatus, string $paymentDate, string $businessDate): void
    {
        $user = $this->cardSignIn($businessDate);
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17, 'credit_limit_centavos' => 500_000]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), $outstanding, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 900_000, 'current_balance_centavos' => 900_000]);

        $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => $amount,
            'payment_date' => $paymentDate,
        ], ['Idempotency-Key' => 'payment-'.$statement->id.'-'.$amount])
            ->assertCreated()
            ->assertJsonPath('data.payment.amount.amount_centavos', $amount)
            ->assertJsonPath('data.payment.status', 'effective')
            ->assertJsonPath('data.statement.paid_amount.amount_centavos', $amount)
            ->assertJsonPath('data.statement.outstanding_amount.amount_centavos', max(0, $outstanding - $amount))
            ->assertJsonPath('data.statement.status', $expectedStatus);

        $this->assertSame(900_000 - $amount, $account->refresh()->current_balance_centavos);
        $summary = $this->cardSummary($card->refresh());
        $this->assertSame(max(0, $outstanding - $amount), $summary['used_credit_centavos']);
    }

    #[Test]
    public function partial_then_full_payment_settles_the_statement(): void
    {
        $user = $this->cardSignIn('2026-08-16');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 60_000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 200_000, 'current_balance_centavos' => 200_000]);

        $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 20_000,
            'payment_date' => '2026-08-15',
        ], ['Idempotency-Key' => 'partial-payment'])
            ->assertCreated()
            ->assertJsonPath('data.statement.status', 'partially_paid')
            ->assertJsonPath('data.statement.outstanding_amount.amount_centavos', 40_000);

        $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 40_000,
            'payment_date' => '2026-08-16',
        ], ['Idempotency-Key' => 'final-payment'])
            ->assertCreated()
            ->assertJsonPath('data.statement.status', 'paid')
            ->assertJsonPath('data.statement.outstanding_amount.amount_centavos', 0);

        $this->assertSame(140_000, $account->refresh()->current_balance_centavos);
        $this->assertSame(0, $this->cardSummary($card->refresh())['used_credit_centavos']);
        $this->assertSame(60_000, (int) $statement->refresh()->paid_centavos);
    }

    #[Test]
    public function effective_payment_may_overdraw_the_paying_account(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 10_000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 5_000, 'current_balance_centavos' => 5_000]);

        $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 10_000,
            'payment_date' => '2026-09-18',
        ], ['Idempotency-Key' => 'overdraft'])
            ->assertCreated()
            ->assertJsonPath('data.statement.status', 'paid');

        $this->assertSame(-5_000, $account->refresh()->current_balance_centavos);
    }

    #[Test]
    public function repeated_submission_replays_without_moving_the_account_twice(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 20_000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);
        $payload = [
            'financial_account_id' => $account->id,
            'amount_centavos' => 20_000,
            'payment_date' => '2026-09-18',
        ];

        $first = $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', $payload, ['Idempotency-Key' => 'replay-payment'])
            ->assertCreated();

        $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', $payload, ['Idempotency-Key' => 'replay-payment'])
            ->assertCreated()
            ->assertExactJson($first->json());

        $this->assertSame(80_000, $account->refresh()->current_balance_centavos);
        $this->assertSame(1, CreditCardStatementPayment::count());
    }

    #[Test]
    public function payment_edits_restate_account_and_statement_exactly_once(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 60_000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;
        $first = $this->cardAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);
        $second = $this->cardAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);

        $paymentId = $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', [
            'financial_account_id' => $first->id,
            'amount_centavos' => 20_000,
            'payment_date' => '2026-09-15',
        ], ['Idempotency-Key' => 'edit-payment-create'])->assertCreated()->json('data.payment.id');

        $this->patchJson('/api/v1/credit-card-payments/'.$paymentId, [
            'financial_account_id' => $second->id,
            'amount_centavos' => 30_000,
        ], ['Idempotency-Key' => 'edit-payment-update'])
            ->assertOk()
            ->assertJsonPath('data.payment.amount.amount_centavos', 30_000)
            ->assertJsonPath('data.payment.financial_account.id', $second->id)
            ->assertJsonPath('data.statement.paid_amount.amount_centavos', 30_000)
            ->assertJsonPath('data.statement.outstanding_amount.amount_centavos', 30_000);

        $this->assertSame(100_000, $first->refresh()->current_balance_centavos);
        $this->assertSame(70_000, $second->refresh()->current_balance_centavos);
        $this->assertSame(1, CreditCardStatementPayment::count());
    }

    #[Test]
    public function removing_and_restoring_a_payment_reverses_and_reapplies_the_settlement(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 40_000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);

        $paymentId = $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 40_000,
            'payment_date' => '2026-09-15',
        ], ['Idempotency-Key' => 'remove-restore-create'])->assertCreated()->json('data.payment.id');

        $this->assertSame('paid', $statement->refresh()->status->value);

        $this->postJson('/api/v1/credit-card-payments/'.$paymentId.'/remove', [], ['Idempotency-Key' => 'remove-payment'])
            ->assertOk()
            ->assertJsonPath('data.payment.is_removed', true)
            ->assertJsonPath('data.statement.outstanding_amount.amount_centavos', 40_000);

        $this->assertSame(100_000, $account->refresh()->current_balance_centavos);
        $this->assertSame(40_000, $this->cardSummary($card->refresh())['used_credit_centavos']);

        $this->postJson('/api/v1/credit-card-payments/'.$paymentId.'/restore', [], ['Idempotency-Key' => 'restore-payment'])
            ->assertOk()
            ->assertJsonPath('data.payment.is_removed', false)
            ->assertJsonPath('data.statement.outstanding_amount.amount_centavos', 0);

        $this->assertSame(60_000, $account->refresh()->current_balance_centavos);

        $this->postJson('/api/v1/credit-card-payments/'.$paymentId.'/remove', [], ['Idempotency-Key' => 'remove-payment-again'])->assertOk();
        $this->postJson('/api/v1/credit-card-payments/'.$paymentId.'/remove', [], ['Idempotency-Key' => 'remove-payment-conflict'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'payment_already_removed');
    }

    #[Test]
    public function pending_payments_do_not_move_money_until_effective(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 30_000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 100_000, 'current_balance_centavos' => 100_000]);

        $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 30_000,
            'payment_date' => '2026-09-25',
        ], ['Idempotency-Key' => 'pending-payment'])
            ->assertCreated()
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.statement.outstanding_amount.amount_centavos', 30_000);

        $this->assertSame(100_000, $account->refresh()->current_balance_centavos);
        $this->assertSame(30_000, $this->cardSummary($card->refresh())['used_credit_centavos']);
    }

    #[Test]
    public function future_dated_effective_payment_is_rejected(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 30_000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;
        $account = $this->cardAccount($user);

        $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 30_000,
            'payment_date' => '2026-09-25',
            'status' => 'effective',
        ], ['Idempotency-Key' => 'future-effective'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidPaymentCases(): array
    {
        return [
            'zero amount' => [['amount_centavos' => 0], 'amount_centavos'],
            'negative amount' => [['amount_centavos' => -1], 'amount_centavos'],
            'foreign account' => [['financial_account_id' => 0], 'financial_account_id'],
            'invalid date' => [['payment_date' => '2026-02-30'], 'payment_date'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPaymentCases')]
    public function invalid_payment_input_is_rejected(array $overrides, string $field): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 30_000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;
        $account = $this->cardAccount($user);

        $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', array_merge([
            'financial_account_id' => $account->id,
            'amount_centavos' => 10_000,
            'payment_date' => '2026-09-18',
        ], $overrides), ['Idempotency-Key' => 'invalid-payment-'.$field])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame(0, CreditCardStatementPayment::count());
        $this->assertSame($account->current_balance_centavos, $account->refresh()->current_balance_centavos);
    }

    #[Test]
    public function payment_above_outstanding_or_on_open_statements_is_rejected(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $category = $this->expenseCategory($user);
        $closedPurchase = $this->recordPurchase($card, $category, 30_000, 1, '2026-08-05');
        $closedStatement = $closedPurchase->installments()->first()->statement;
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 500_000, 'current_balance_centavos' => 500_000]);

        $this->postJson('/api/v1/credit-card-statements/'.$closedStatement->id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 30_001,
            'payment_date' => '2026-09-18',
        ], ['Idempotency-Key' => 'over-outstanding'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'payment_exceeds_outstanding');

        $openPurchase = $this->recordPurchase($card, $category, 10_000, 1, '2026-09-15');
        $openStatement = $openPurchase->installments()->first()->statement;

        $this->postJson('/api/v1/credit-card-statements/'.$openStatement->id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 10_000,
            'payment_date' => '2026-09-18',
        ], ['Idempotency-Key' => 'open-statement'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'statement_not_payable');

        $this->assertSame(0, CreditCardStatementPayment::count());
        $this->assertSame(500_000, $account->refresh()->current_balance_centavos);
    }

    #[Test]
    public function archived_and_foreign_accounts_cannot_receive_new_payments(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 30_000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;
        $archived = FinancialAccount::factory()->archived()->create(['user_id' => $user->id]);
        $foreign = FinancialAccount::factory()->create();

        $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', [
            'financial_account_id' => $archived->id,
            'amount_centavos' => 10_000,
            'payment_date' => '2026-09-18',
        ], ['Idempotency-Key' => 'archived-account'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('financial_account_id');

        $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', [
            'financial_account_id' => $foreign->id,
            'amount_centavos' => 10_000,
            'payment_date' => '2026-09-18',
        ], ['Idempotency-Key' => 'foreign-account'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('financial_account_id');

        $this->assertSame(0, CreditCardStatementPayment::count());
    }

    #[Test]
    public function foreign_payments_are_invisible_for_lifecycle_actions(): void
    {
        $this->cardSignIn('2026-09-20');
        $other = User::factory()->create();
        $foreignCard = $this->activeCard($other, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($foreignCard, $this->expenseCategory($other), 10_000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;
        $account = $this->cardAccount($other);
        $payment = $this->payStatement($statement, $account, 10_000, '2026-09-10');

        $this->patchJson('/api/v1/credit-card-payments/'.$payment->id, ['amount_centavos' => 1], ['Idempotency-Key' => 'foreign-edit'])->assertNotFound();
        $this->postJson('/api/v1/credit-card-payments/'.$payment->id.'/remove', [], ['Idempotency-Key' => 'foreign-remove'])->assertNotFound();
        $this->postJson('/api/v1/credit-card-payments/'.$payment->id.'/restore', [], ['Idempotency-Key' => 'foreign-restore'])->assertNotFound();
    }
}
