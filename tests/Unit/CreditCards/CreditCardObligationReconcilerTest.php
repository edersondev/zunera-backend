<?php

declare(strict_types=1);

namespace Tests\Unit\CreditCards;

use App\Models\CreditCardPaymentSurplusApplication;
use App\Models\User;
use App\Services\CreditCards\CreditCardObligationReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardObligationReconcilerTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function partial_statement_settlement_restates_used_and_available_credit_exactly_once(): void
    {
        $user = User::factory()->create();
        $card = $this->activeCard($user, ['credit_limit_centavos' => 10_000]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 10_000, 3, '2026-09-05');
        $reconciler = app(CreditCardObligationReconciler::class);

        self::assertSame([
            'credit_limit_centavos' => 10_000,
            'used_credit_centavos' => 10_000,
            'card_credit_centavos' => 0,
            'available_credit_centavos' => 0,
            'is_over_limit' => false,
        ], $reconciler->summary($card));

        $statement = $purchase->installments()->orderBy('sequence')->firstOrFail()->statement;
        $this->payStatement($statement, $this->cardAccount($user), 3_334);

        self::assertSame(6_666, $reconciler->usedCreditCentavos($card));
        self::assertSame(3_334, $reconciler->availableCreditCentavos($card));
        self::assertSame(3_334, $reconciler->summary($card)['available_credit_centavos']);
    }

    #[Test]
    public function retained_payment_surplus_pays_the_oldest_unpaid_statement_without_moving_cash(): void
    {
        $this->freezeCardClock();
        $user = User::factory()->create();
        $card = $this->activeCard($user, ['credit_limit_centavos' => 10_000]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 10_000, 2, '2026-09-05');
        $installments = $purchase->installments()->orderBy('sequence')->get();
        $source = $installments[0]->statement;
        $destination = $installments[1]->statement;
        $account = $this->cardAccount($user);
        $payment = $this->payStatement($source, $account, 5_000);
        $accountBalance = $account->fresh()->balance_centavos;

        $installments[0]->update(['credit_card_statement_id' => $destination->id]);
        $reconciler = app(CreditCardObligationReconciler::class);
        $reconciler->refreshCardStatements($card, CarbonImmutable::parse('2026-09-20'));

        self::assertSame(5_000, $source->fresh()->paid_centavos);
        self::assertSame(0, $source->fresh()->outstandingCentavos());
        self::assertSame(5_000, $destination->fresh()->card_credit_applied_centavos);
        self::assertSame(5_000, $reconciler->usedCreditCentavos($card));
        self::assertSame(0, $reconciler->cardCreditCentavos($card));
        self::assertSame($accountBalance, $account->fresh()->balance_centavos);
        self::assertSame(5_000, $payment->fresh()->amount_centavos);
    }

    #[Test]
    public function retained_payment_after_charge_removal_becomes_visible_card_credit(): void
    {
        $this->freezeCardClock();
        $user = User::factory()->create();
        $card = $this->activeCard($user, ['credit_limit_centavos' => 10_000]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 5_000, 1, '2026-09-05');
        $statement = $purchase->installments()->firstOrFail()->statement;
        $this->payStatement($statement, $this->cardAccount($user), 5_000);
        $purchase->delete();

        $reconciler = app(CreditCardObligationReconciler::class);
        $reconciler->refreshCardStatements($card, CarbonImmutable::parse('2026-09-20'));

        self::assertSame(5_000, $statement->fresh()->paid_centavos);
        self::assertSame(0, $statement->fresh()->outstandingCentavos());
        self::assertSame(0, $reconciler->usedCreditCentavos($card));
        self::assertSame(5_000, $reconciler->cardCreditCentavos($card));
        self::assertSame(15_000, $reconciler->availableCreditCentavos($card));
    }

    #[Test]
    public function payment_update_remove_and_restore_rebuild_active_surplus_once(): void
    {
        $this->freezeCardClock();
        $user = User::factory()->create();
        $card = $this->activeCard($user);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 10_000, 2, '2026-09-05');
        $installments = $purchase->installments()->orderBy('sequence')->get();
        $source = $installments[0]->statement;
        $destination = $installments[1]->statement;
        $payment = $this->payStatement($source, $this->cardAccount($user), 5_000);
        $installments[0]->update(['credit_card_statement_id' => $destination->id]);
        $reconciler = app(CreditCardObligationReconciler::class);
        $businessDate = CarbonImmutable::parse('2026-09-20');
        $reconciler->refreshCardStatements($card, $businessDate);

        self::assertSame(5_000, $this->activeSurplusFor($destination->id));
        $reconciler->refreshCardStatements($card, $businessDate);
        self::assertSame(1, CreditCardPaymentSurplusApplication::query()->active()->count());

        $payment->update(['amount_centavos' => 3_000]);
        $reconciler->refreshCardStatements($card, $businessDate);
        self::assertSame(3_000, $this->activeSurplusFor($destination->id));
        self::assertSame(7_000, $destination->fresh()->outstandingCentavos());

        $payment->update(['removed_at' => now()]);
        $reconciler->refreshCardStatements($card, $businessDate);
        self::assertSame(0, $this->activeSurplusFor($destination->id));
        self::assertSame(10_000, $destination->fresh()->outstandingCentavos());

        $payment->update(['removed_at' => null]);
        $reconciler->refreshCardStatements($card, $businessDate);
        self::assertSame(3_000, $this->activeSurplusFor($destination->id));
        self::assertSame(7_000, $destination->fresh()->outstandingCentavos());
        self::assertGreaterThan(0, CreditCardPaymentSurplusApplication::query()->whereNotNull('superseded_at')->count());
    }

    private function activeSurplusFor(int $statementId): int
    {
        return (int) CreditCardPaymentSurplusApplication::query()
            ->where('destination_statement_id', $statementId)
            ->active()
            ->sum('amount_centavos');
    }
}
