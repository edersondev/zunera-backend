<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\CreditCardInstallment;
use App\Models\CreditCardMutationRequest;
use App\Models\CreditCardPurchase;
use App\Models\CreditCardStatement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class FoundationalCreditCardDomainTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function purchase_persistence_replays_safely_and_reconciles_exact_centavos(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, [
            'credit_limit_centavos' => 10_000,
            'closing_day' => 10,
            'due_day' => 17,
        ]);
        $category = $this->expenseCategory($user);
        $account = $this->cardAccount($user, [
            'initial_balance_centavos' => 50_000,
            'current_balance_centavos' => 50_000,
        ]);
        $payload = [
            'category_id' => $category->id,
            'description' => 'Compra parcelada',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 10_000,
            'installment_count' => 3,
        ];

        $first = $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', $payload, [
            'Idempotency-Key' => 'foundational-purchase',
        ])
            ->assertCreated()
            ->assertJsonPath('data.installments.0.amount.amount_centavos', 3_334)
            ->assertJsonPath('data.installments.1.amount.amount_centavos', 3_333)
            ->assertJsonPath('data.installments.2.amount.amount_centavos', 3_333);

        $this->assertSame(1, CreditCardPurchase::count());
        $this->assertSame(3, CreditCardInstallment::count());
        $this->assertSame(3, CreditCardStatement::count());
        $this->assertSame(1, CreditCardMutationRequest::count());
        $this->assertSame(50_000, $account->refresh()->current_balance_centavos);
        $this->assertSame(10_000, $this->cardSummary($card->refresh())['used_credit_centavos']);
        $this->assertSame(0, $this->cardSummary($card->refresh())['available_credit_centavos']);

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', $payload, [
            'Idempotency-Key' => 'foundational-purchase',
        ])
            ->assertCreated()
            ->assertExactJson($first->json());

        $this->assertSame(1, CreditCardPurchase::count());
        $this->assertSame(3, CreditCardInstallment::count());
        $this->assertSame(1, CreditCardMutationRequest::count());

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', [
            ...$payload,
            'description' => 'Mesmo identificador, outro pedido',
        ], ['Idempotency-Key' => 'foundational-purchase'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_key_reused');

        $statement = CreditCardStatement::query()
            ->orderBy('closing_date')
            ->firstOrFail();
        $this->payStatement($statement, $account, 3_334, '2026-09-18');

        $summary = $this->cardSummary($card->refresh());
        $this->assertSame(6_666, $summary['used_credit_centavos']);
        $this->assertSame(3_334, $summary['available_credit_centavos']);
        $this->assertSame(46_666, $account->refresh()->current_balance_centavos);
    }

    #[Test]
    public function foreign_card_domain_records_are_not_discoverable(): void
    {
        $this->cardSignIn('2026-09-20');
        $foreignOwner = User::factory()->create();
        $foreignCard = $this->activeCard($foreignOwner);
        $purchase = $this->recordPurchase(
            $foreignCard,
            $this->expenseCategory($foreignOwner),
            1_000,
            1,
            '2026-09-05',
        );

        $this->getJson('/api/v1/credit-cards/'.$foreignCard->id)->assertNotFound();
        $this->getJson('/api/v1/credit-card-purchases/'.$purchase->id)->assertNotFound();
        $this->getJson('/api/v1/credit-card-statements/'.$purchase->installments()->firstOrFail()->credit_card_statement_id)
            ->assertNotFound();
    }
}
