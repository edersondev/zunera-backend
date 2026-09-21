<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\CreditCardPurchase;
use App\Models\CreditCardStatementPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardAuthorizationTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function a_user_cannot_read_or_mutate_another_users_credit_card_domain_or_reference_their_resources(): void
    {
        $owner = $this->cardSignIn('2026-09-20');
        $ownerCard = $this->activeCard($owner);
        $ownerCategory = $this->expenseCategory($owner);
        $ownerPurchase = $this->recordPurchase($ownerCard, $ownerCategory, 10_000, 1, '2026-08-05');
        $ownerStatement = $ownerPurchase->installments()->first()->statement;
        $ownerAccount = $this->cardAccount($owner, [
            'initial_balance_centavos' => 100_000,
            'current_balance_centavos' => 100_000,
        ]);

        $foreignOwner = User::factory()->create();
        $foreignCard = $this->activeCard($foreignOwner);
        $foreignCategory = $this->expenseCategory($foreignOwner);
        $foreignPurchase = $this->recordPurchase($foreignCard, $foreignCategory, 10_000, 1, '2026-08-05');
        $foreignStatement = $foreignPurchase->installments()->first()->statement;
        $foreignAccount = $this->cardAccount($foreignOwner, [
            'initial_balance_centavos' => 100_000,
            'current_balance_centavos' => 100_000,
        ]);
        $foreignPayment = $this->payStatement($foreignStatement, $foreignAccount, 10_000, '2026-09-10');

        $this->getJson('/api/v1/credit-cards/'.$foreignCard->id)->assertNotFound();
        $this->patchJson('/api/v1/credit-cards/'.$foreignCard->id, ['name' => 'Invasão'], ['Idempotency-Key' => 'foreign-card-update'])->assertNotFound();
        $this->postJson('/api/v1/credit-cards/'.$foreignCard->id.'/archive', [], ['Idempotency-Key' => 'foreign-card-archive'])->assertNotFound();

        $this->getJson('/api/v1/credit-cards/'.$foreignCard->id.'/purchases')->assertNotFound();
        $this->postJson('/api/v1/credit-cards/'.$foreignCard->id.'/purchases', [
            'category_id' => $ownerCategory->id,
            'description' => 'Invasão',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 1_000,
            'installment_count' => 1,
        ], ['Idempotency-Key' => 'foreign-purchase-create'])->assertNotFound();
        $this->getJson('/api/v1/credit-card-purchases/'.$foreignPurchase->id)->assertNotFound();
        $this->patchJson('/api/v1/credit-card-purchases/'.$foreignPurchase->id, ['description' => 'Invasão'], ['Idempotency-Key' => 'foreign-purchase-update'])->assertNotFound();
        $this->postJson('/api/v1/credit-card-purchases/'.$foreignPurchase->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => 1_000,
            'event_date' => '2026-09-18',
        ], ['Idempotency-Key' => 'foreign-credit-event'])->assertNotFound();

        $this->getJson('/api/v1/credit-cards/'.$foreignCard->id.'/statements')->assertNotFound();
        $this->getJson('/api/v1/credit-card-statements/'.$foreignStatement->id)->assertNotFound();
        $this->postJson('/api/v1/credit-card-statements/'.$foreignStatement->id.'/payments', [
            'financial_account_id' => $ownerAccount->id,
            'amount_centavos' => 1_000,
            'payment_date' => '2026-09-18',
        ], ['Idempotency-Key' => 'foreign-statement-payment'])->assertNotFound();
        $this->patchJson('/api/v1/credit-card-payments/'.$foreignPayment->id, ['amount_centavos' => 1], ['Idempotency-Key' => 'foreign-payment-update'])->assertNotFound();
        $this->postJson('/api/v1/credit-card-payments/'.$foreignPayment->id.'/remove', [], ['Idempotency-Key' => 'foreign-payment-remove'])->assertNotFound();
        $this->postJson('/api/v1/credit-card-payments/'.$foreignPayment->id.'/restore', [], ['Idempotency-Key' => 'foreign-payment-restore'])->assertNotFound();

        $this->postJson('/api/v1/credit-cards/'.$ownerCard->id.'/purchases', [
            'category_id' => $foreignCategory->id,
            'description' => 'Categoria alheia',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 1_000,
            'installment_count' => 1,
        ], ['Idempotency-Key' => 'foreign-category'])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->postJson('/api/v1/credit-card-statements/'.$ownerStatement->id.'/payments', [
            'financial_account_id' => $foreignAccount->id,
            'amount_centavos' => 1_000,
            'payment_date' => '2026-09-18',
        ], ['Idempotency-Key' => 'foreign-account'])->assertUnprocessable()->assertJsonValidationErrors('financial_account_id');

        $this->assertSame('active', $foreignCard->refresh()->status->value);
        $this->assertSame('Compra teste', $foreignPurchase->refresh()->description);
        $this->assertSame(2, CreditCardPurchase::count());
        $this->assertSame(1, CreditCardStatementPayment::count());
        $this->assertSame(100_000, $ownerAccount->refresh()->current_balance_centavos);
        $this->assertSame(90_000, $foreignAccount->refresh()->current_balance_centavos);
    }
}
