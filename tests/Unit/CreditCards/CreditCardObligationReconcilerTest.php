<?php

declare(strict_types=1);

namespace Tests\Unit\CreditCards;

use App\Models\User;
use App\Services\CreditCards\CreditCardObligationReconciler;
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
}
