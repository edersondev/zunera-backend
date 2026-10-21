<?php

declare(strict_types=1);

namespace Tests\Feature\AccountData;

use App\Models\CreditCard;
use App\Models\CreditCardPaymentSurplusApplication;
use App\Models\CreditCardPurchase;
use App\Models\CreditCardPurchaseMove;
use App\Services\CreditCards\CreditCardObligationReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardArchiveRestoreTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function archive_restores_move_history_and_payment_surplus_allocations(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $account = $this->cardAccount($user);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 10_000, 1, '2026-08-05');
        $source = $purchase->installments()->firstOrFail()->statement;
        $this->payStatement($source, $account, 10_000, '2026-08-17');

        $choice = ['mode' => 'full_schedule', 'target_closing_date' => '2026-10-10'];
        $preview = $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move-preview", $choice)->assertOk();
        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", [
            ...$choice, 'preview_token' => $preview->json('data.preview_token'),
        ], ['Idempotency-Key' => 'move-before-archive'])->assertOk();

        self::assertSame(1, CreditCardPurchaseMove::query()->where('user_id', $user->id)->count());
        self::assertSame(10_000, CreditCardPaymentSurplusApplication::query()->active()
            ->where('user_id', $user->id)->sum('amount_centavos'));
        self::assertSame(0, $this->cardSummary($card)['card_credit_centavos']);

        $archiveId = (int) $this->postJson('/api/v1/account-data/archive', [])->assertCreated()->json('data.id');
        self::assertSame(0, CreditCardPurchaseMove::query()->where('user_id', $user->id)->count());
        self::assertSame(0, CreditCardPaymentSurplusApplication::query()->where('user_id', $user->id)->count());

        $this->postJson("/api/v1/account-data/archives/{$archiveId}/restore", [])->assertOk();
        $restoredCard = CreditCard::query()->where('user_id', $user->id)->firstOrFail();
        $restoredPurchase = CreditCardPurchase::query()->where('user_id', $user->id)->firstOrFail();
        $restoredMove = CreditCardPurchaseMove::query()->where('user_id', $user->id)->firstOrFail();
        $restoredApplication = CreditCardPaymentSurplusApplication::query()->active()
            ->where('user_id', $user->id)->firstOrFail();

        self::assertSame($restoredPurchase->id, $restoredMove->credit_card_purchase_id);
        self::assertSame($restoredCard->id, $restoredMove->credit_card_id);
        self::assertSame($restoredCard->id, $restoredApplication->credit_card_id);
        self::assertSame($restoredMove->destination_statement_id, $restoredApplication->destination_statement_id);
        self::assertSame(10_000, $restoredApplication->amount_centavos);
        self::assertSame(0, app(CreditCardObligationReconciler::class)->cardCreditCentavos($restoredCard));
    }
}
