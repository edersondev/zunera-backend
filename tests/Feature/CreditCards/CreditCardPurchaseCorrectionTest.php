<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\CreditCardInstallment;
use App\Models\CreditCardPurchase;
use App\Models\CreditCardStatement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardPurchaseCorrectionTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function owner_corrects_an_open_purchase_and_installments_are_restated(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17, 'credit_limit_centavos' => 500_000]);
        $category = $this->expenseCategory($user);
        $other = $this->expenseCategory($user, ['name' => 'Lazer']);
        $purchase = $this->recordPurchase($card, $category, 100_00, 1, '2026-09-15');

        $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, [
            'description' => 'Valor corrigido',
            'total_amount_centavos' => 90_00,
            'installment_count' => 3,
            'category_id' => $other->id,
            'purchase_date' => '2026-09-16',
        ], ['Idempotency-Key' => 'correction-open'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Valor corrigido')
            ->assertJsonPath('data.total_amount.amount_centavos', 9_000)
            ->assertJsonPath('data.installment_count', 3)
            ->assertJsonPath('data.category.id', $other->id)
            ->assertJsonCount(3, 'data.installments')
            ->assertJsonPath('data.is_directly_editable', true);

        $this->assertSame(9_000, (int) CreditCardInstallment::query()->where('credit_card_purchase_id', $purchase->id)->sum('amount_centavos'));
        $this->assertSame([3_000, 3_000, 3_000], CreditCardInstallment::query()
            ->where('credit_card_purchase_id', $purchase->id)
            ->orderBy('sequence')
            ->pluck('amount_centavos')
            ->all());

        $summary = $this->cardSummary($card->refresh());
        $this->assertSame(9_000, $summary['used_credit_centavos']);
        $this->assertSame(491_000, $summary['available_credit_centavos']);
    }

    #[Test]
    public function installed_installments_follow_the_corrected_purchase_date(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 30_000, 3, '2026-09-15');

        $response = $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, [
            'purchase_date' => '2026-09-16',
            'installment_count' => 1,
        ], ['Idempotency-Key' => 'correction-dates'])->assertOk();

        $this->assertSame(['2026-10-10'], collect($response->json('data.installments'))
            ->pluck('statement.closing_date')
            ->all());

        // Statements that lost their only installment are pruned from history.
        $this->assertSame(['2026-10-10'], CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->orderBy('closing_date')
            ->pluck('closing_date')
            ->map(fn ($date) => $date->toDateString())
            ->all());
    }

    #[Test]
    public function purchase_with_a_closed_installment_cannot_be_corrected_directly(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 30_000, 1, '2026-08-05');

        $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, [
            'total_amount_centavos' => 20_000,
        ], ['Idempotency-Key' => 'correction-closed'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'purchase_not_directly_editable');

        $this->assertSame(30_000, (int) $purchase->installments()->sum('amount_centavos'));
    }

    #[Test]
    public function correction_can_move_an_open_purchase_to_another_owned_card(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17, 'credit_limit_centavos' => 500_000]);
        $target = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17, 'credit_limit_centavos' => 500_000, 'name' => 'Segundo']);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 30_000, 1, '2026-09-15');

        $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, [
            'card_id' => $target->id,
        ], ['Idempotency-Key' => 'correction-card'])->assertOk();

        $this->assertSame(0, $this->cardSummary($card->refresh())['used_credit_centavos']);
        $this->assertSame(30_000, $this->cardSummary($target->refresh())['used_credit_centavos']);
        $this->assertSame($target->id, $purchase->refresh()->credit_card_id);
    }

    #[Test]
    public function correction_above_available_credit_requires_explicit_confirmation(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17, 'credit_limit_centavos' => 100_000]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 50_000, 1, '2026-09-15');

        $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, [
            'total_amount_centavos' => 150_000,
        ], ['Idempotency-Key' => 'correction-over-limit'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'OVER_LIMIT_CONFIRMATION_REQUIRED')
            ->assertJsonPath('resulting_available_credit.amount_centavos', -50_000);

        $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, [
            'total_amount_centavos' => 150_000,
            'confirm_over_limit' => true,
            'expected_available_credit_centavos' => 100_000,
        ], ['Idempotency-Key' => 'correction-confirmed'])
            ->assertOk()
            ->assertJsonPath('data.total_amount.amount_centavos', 150_000);

        $this->assertSame(-50_000, $this->cardSummary($card->refresh())['available_credit_centavos']);
    }

    #[Test]
    public function correction_replays_and_guards_ownership(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 30_000, 1, '2026-09-15');
        $payload = ['description' => 'Corrigida'];

        $first = $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, $payload, ['Idempotency-Key' => 'correction-replay'])->assertOk();
        $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, $payload, ['Idempotency-Key' => 'correction-replay'])
            ->assertOk()
            ->assertExactJson($first->json());

        $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, ['description' => 'Outra'], ['Idempotency-Key' => 'correction-replay'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_key_reused');

        $other = User::factory()->create();
        $foreign = $this->recordPurchase($this->activeCard($other), $this->expenseCategory($other), 1_000, 1, '2026-09-15');
        $this->patchJson('/api/v1/credit-card-purchases/'.$foreign->id, ['description' => 'Invasão'], ['Idempotency-Key' => 'correction-foreign'])
            ->assertNotFound();
        $this->assertSame(0, CreditCardPurchase::query()->where('description', 'Outra')->count());
        $this->assertSame('Corrigida', $purchase->refresh()->description);
    }

    #[Test]
    public function correction_rejects_foreign_or_invalid_values(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 30_000, 1, '2026-09-15');
        $other = User::factory()->create();
        $foreignCategory = $this->expenseCategory($other);

        $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, ['category_id' => $foreignCategory->id], ['Idempotency-Key' => 'correction-foreign-category'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
        $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, ['installment_count' => 361], ['Idempotency-Key' => 'correction-bad-count'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('installment_count');
        $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, ['total_amount_centavos' => 0], ['Idempotency-Key' => 'correction-zero'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_amount_centavos');
        $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, [], ['Idempotency-Key' => 'correction-empty'])
            ->assertUnprocessable();

        $this->assertSame(30_000, (int) $purchase->fresh()->total_amount_centavos);
    }
}
