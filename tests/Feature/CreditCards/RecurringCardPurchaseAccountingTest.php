<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Services\CreditCards\CreditCardObligationReconciler;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\RecurringTransactions\RecurringTransactionFeatureTestCase;
use Tests\Support\CreditCards\CreditCardFixtures;

final class RecurringCardPurchaseAccountingTest extends RecurringTransactionFeatureTestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function late_automatic_purchase_restates_a_paid_statement_without_replaying_its_payment(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $account = $this->ownedAccount($user, 100_000);
        $manualPurchase = $this->recordPurchase($card, $category, 10_000, 1, '2026-08-05');
        $statement = $manualPurchase->installments()->firstOrFail()->statement;
        $this->payStatement($statement, $account, 10_000, '2026-08-17');
        self::assertSame(90_000, $account->fresh()->current_balance_centavos);

        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'start_date' => '2026-08-10',
            'eligibility_starts_on' => '2026-08-10',
            'schedule_cursor' => '2026-08-10',
            'end_date' => '2026-08-10',
        ]);
        self::assertSame(1, app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24'));

        self::assertSame($statement->id, $rule->cardOccurrences()->firstOrFail()->purchase->installments()->firstOrFail()->credit_card_statement_id);
        self::assertSame(25_000, $statement->fresh()->original_amount_centavos);
        self::assertSame(10_000, $statement->fresh()->paid_centavos);
        self::assertSame(15_000, $statement->fresh()->outstandingCentavos());
        self::assertSame(90_000, $account->fresh()->current_balance_centavos);
        self::assertSame(15_000, app(CreditCardObligationReconciler::class)->summary($card->fresh())['used_credit_centavos']);
    }

    #[Test]
    public function late_automatic_purchase_restates_an_unpaid_closed_statement(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $manualPurchase = $this->recordPurchase($card, $category, 10_000, 1, '2026-08-05');
        $statement = $manualPurchase->installments()->firstOrFail()->statement;

        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'start_date' => '2026-08-10',
            'eligibility_starts_on' => '2026-08-10',
            'schedule_cursor' => '2026-08-10',
            'end_date' => '2026-08-10',
        ]);
        self::assertSame(1, app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24'));

        self::assertSame($statement->id, $rule->cardOccurrences()->firstOrFail()->purchase->installments()->firstOrFail()->credit_card_statement_id);
        self::assertSame(25_000, $statement->fresh()->original_amount_centavos);
        self::assertSame(0, $statement->fresh()->paid_centavos);
        self::assertSame(25_000, $statement->fresh()->outstandingCentavos());
    }

    #[Test]
    #[DataProvider('closingBoundaryCases')]
    public function one_recurring_installment_uses_the_existing_closing_cycle_without_moving_cash(
        string $purchaseDate,
        string $closingDate,
    ): void {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user, 250_000);
        $card = $this->ownedCard($user, 1_000_000);
        $card->update(['closing_day' => 10, 'due_day' => 17]);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'start_date' => $purchaseDate,
            'eligibility_starts_on' => $purchaseDate,
            'schedule_cursor' => $purchaseDate,
            'end_date' => $purchaseDate,
        ]);

        self::assertSame(1, app(RecurringOccurrenceService::class)->processRule($rule->id, $purchaseDate));

        $occurrence = $rule->cardOccurrences()->firstOrFail();
        $purchase = $occurrence->purchase;
        self::assertNotNull($purchase);
        self::assertSame($purchaseDate, $purchase->purchase_date->toDateString());
        self::assertSame(1, $purchase->installment_count);
        self::assertSame(1, $purchase->installments()->count());
        self::assertSame($closingDate, $purchase->installments()->firstOrFail()->statement->closing_date->toDateString());
        self::assertSame(250_000, $account->fresh()->current_balance_centavos);

        $summary = app(CreditCardObligationReconciler::class)->summary($card->fresh());
        self::assertSame(15_000, $summary['used_credit_centavos']);
        self::assertSame(985_000, $summary['available_credit_centavos']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function closingBoundaryCases(): iterable
    {
        $firstMonth = new DateTimeImmutable('2020-01-01');
        for ($offset = 0; $offset < 50; $offset++) {
            $month = $firstMonth->modify("+$offset months");
            $closingDate = $month->format('Y-m-10');
            yield "closing day / $offset" => [$closingDate, $closingDate];

            $nextMonth = $month->modify('+1 month');
            yield "after closing / $offset" => [$month->format('Y-m-11'), $nextMonth->format('Y-m-10')];
        }
    }
}
