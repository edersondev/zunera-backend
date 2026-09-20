<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\CreditCard;
use App\Models\CreditCardInstallment;
use App\Models\CreditCardPurchase;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardPurchaseTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function owner_records_a_single_payment_purchase_without_moving_account_balances(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user, ['credit_limit_centavos' => 500_000]);
        $category = $this->expenseCategory($user);
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 900_000, 'current_balance_centavos' => 900_000]);

        $response = $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', [
            'category_id' => $category->id,
            'description' => 'Mercado',
            'purchase_date' => '2026-09-15',
            'total_amount_centavos' => 20_000,
            'installment_count' => 1,
        ], ['Idempotency-Key' => 'purchase-1'])
            ->assertCreated()
            ->assertJsonPath('data.description', 'Mercado')
            ->assertJsonPath('data.total_amount.amount_centavos', 20_000)
            ->assertJsonPath('data.installment_count', 1)
            ->assertJsonPath('data.installments.0.sequence', 1)
            ->assertJsonPath('data.installments.0.amount.amount_centavos', 20_000)
            ->assertJsonPath('data.installments.0.recognition_status', 'pending')
            ->assertJsonPath('data.installments.0.statement.closing_date', '2026-10-10')
            ->assertJsonPath('data.is_directly_editable', true);

        $this->assertSame(1, CreditCardPurchase::count());
        $this->assertSame(900_000, $account->refresh()->current_balance_centavos);

        $summary = $this->cardSummary($card->refresh());
        $this->assertSame(20_000, $summary['used_credit_centavos']);
        $this->assertSame(480_000, $summary['available_credit_centavos']);
        $this->assertFalse($summary['is_over_limit']);
    }

    #[Test]
    public function purchase_on_the_closing_date_belongs_to_the_statement_closing_that_day(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', [
            'category_id' => $this->expenseCategory($user)->id,
            'description' => 'Compra no fechamento',
            'purchase_date' => '2026-09-10',
            'total_amount_centavos' => 5_000,
            'installment_count' => 1,
        ], ['Idempotency-Key' => 'closing-day'])
            ->assertCreated()
            ->assertJsonPath('data.installments.0.statement.closing_date', '2026-09-10')
            ->assertJsonPath('data.installments.0.statement.due_date', '2026-09-17');

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', [
            'category_id' => $this->expenseCategory($user)->id,
            'description' => 'Compra pós-fechamento',
            'purchase_date' => '2026-09-11',
            'total_amount_centavos' => 5_000,
            'installment_count' => 1,
        ], ['Idempotency-Key' => 'after-closing'])
            ->assertCreated()
            ->assertJsonPath('data.installments.0.statement.closing_date', '2026-10-10');
    }

    #[Test]
    public function archived_cards_reject_new_purchases(): void
    {
        $user = $this->cardSignIn();
        $card = $this->archivedCard($user);

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', [
            'category_id' => $this->expenseCategory($user)->id,
            'description' => 'Compra',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 1_000,
            'installment_count' => 1,
        ], ['Idempotency-Key' => 'archived-card'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'card_archived');

        $this->assertSame(0, CreditCardPurchase::count());
    }

    #[Test]
    public function over_limit_purchase_requires_explicit_confirmation_with_a_fresh_key(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user, ['credit_limit_centavos' => 100_000]);
        $category = $this->expenseCategory($user);
        $payload = [
            'category_id' => $category->id,
            'description' => 'Compra acima do limite',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 150_000,
            'installment_count' => 1,
        ];

        $confirmation = $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', $payload, ['Idempotency-Key' => 'over-limit-initial'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'OVER_LIMIT_CONFIRMATION_REQUIRED')
            ->assertJsonPath('is_over_limit', true)
            ->assertJsonPath('resulting_available_credit.amount_centavos', -50_000);

        $this->assertSame(0, CreditCardPurchase::count());

        // A network replay of the identical initial mutation repeats the typed warning.
        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', $payload, ['Idempotency-Key' => 'over-limit-initial'])
            ->assertStatus(409)
            ->assertExactJson($confirmation->json());

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', $payload + [
            'confirm_over_limit' => true,
            'expected_available_credit_centavos' => 100_000,
        ], ['Idempotency-Key' => 'over-limit-confirmed'])
            ->assertCreated()
            ->assertJsonPath('data.total_amount.amount_centavos', 150_000);

        $summary = $this->cardSummary($card->refresh());
        $this->assertSame(-50_000, $summary['available_credit_centavos']);
        $this->assertTrue($summary['is_over_limit']);

        $this->assertDatabaseHas('credit_cards', ['id' => $card->id, 'credit_limit_centavos' => 100_000]);
    }

    #[Test]
    public function stale_over_limit_confirmation_is_rejected_after_availability_changes(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user, ['credit_limit_centavos' => 100_000]);
        $category = $this->expenseCategory($user);
        $this->recordPurchase($card, $category, 60_000, 1, '2026-09-01');

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', [
            'category_id' => $category->id,
            'description' => 'Confirmação desatualizada',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 80_000,
            'installment_count' => 1,
            'confirm_over_limit' => true,
            'expected_available_credit_centavos' => 100_000,
        ], ['Idempotency-Key' => 'stale-confirmation'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_over_limit_confirmation');
    }

    #[Test]
    public function owner_lists_purchases_of_a_card_with_pagination_meta(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user);
        $category = $this->expenseCategory($user);
        $this->recordPurchase($card, $category, 1_000, 1, '2026-09-01');
        $this->recordPurchase($card, $category, 2_000, 1, '2026-09-02');

        $this->getJson('/api/v1/credit-cards/'.$card->id.'/purchases')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 50);
    }

    #[Test]
    public function foreign_purchases_and_cards_are_invisible(): void
    {
        $this->cardSignIn();
        $foreignUser = User::factory()->create();
        $foreignCard = $this->activeCard($foreignUser);
        $foreignPurchase = $this->recordPurchase($foreignCard, $this->expenseCategory($foreignUser), 1_000, 1, '2026-09-01');

        $this->getJson('/api/v1/credit-card-purchases/'.$foreignPurchase->id)->assertNotFound();
        $this->getJson('/api/v1/credit-cards/'.$foreignCard->id.'/purchases')->assertNotFound();
        $this->postJson('/api/v1/credit-cards/'.$foreignCard->id.'/purchases', [
            'category_id' => 1,
            'description' => 'Compra',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 1_000,
            'installment_count' => 1,
        ], ['Idempotency-Key' => 'foreign-purchase'])->assertNotFound();
    }

    #[Test]
    public function purchase_does_not_touch_unrelated_accounts_or_create_cash_events(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user);
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 10_000, 'current_balance_centavos' => 10_000]);

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', [
            'category_id' => $this->expenseCategory($user)->id,
            'description' => 'Compra',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 25_000,
            'installment_count' => 1,
        ], ['Idempotency-Key' => 'no-cash-movement'])->assertCreated();

        $this->assertSame(10_000, $account->refresh()->current_balance_centavos);
        $this->assertSame(0, Transaction::count());
        $this->assertSame(1, CreditCardInstallment::count());
    }

    #[Test]
    public function purchase_accepts_the_maximum_installment_count_and_limit_boundaries(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user, ['credit_limit_centavos' => 99_999_999_999]);

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', [
            'category_id' => $this->expenseCategory($user)->id,
            'description' => 'Compra longa',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 360_00,
            'installment_count' => 360,
        ], ['Idempotency-Key' => 'max-installments'])
            ->assertCreated()
            ->assertJsonCount(360, 'data.installments');
    }

    #[Test]
    public function default_category_argument_is_owned_by_the_signed_in_user_only(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user);
        $other = User::factory()->create();
        $foreignCategory = $this->expenseCategory($other);

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', [
            'category_id' => $foreignCategory->id,
            'description' => 'Compra',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 1_000,
            'installment_count' => 1,
        ], ['Idempotency-Key' => 'foreign-category'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');

        $this->assertSame(0, CreditCardPurchase::count());
        $this->assertSame(1, CreditCard::count());
        $this->assertSame(0, FinancialAccount::count());
    }
}
