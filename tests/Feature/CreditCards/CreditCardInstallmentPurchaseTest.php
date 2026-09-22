<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Enums\Categories\CategoryClassification;
use App\Enums\Categories\CategoryStatus;
use App\Models\Category;
use App\Models\CreditCardInstallment;
use App\Models\CreditCardPurchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardInstallmentPurchaseTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function six_installments_are_allocated_in_sequence_with_exact_total(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user, ['credit_limit_centavos' => 500_000, 'closing_day' => 10, 'due_day' => 17]);

        $response = $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', [
            'category_id' => $this->expenseCategory($user)->id,
            'description' => 'Compra parcelada',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 120_000,
            'installment_count' => 6,
        ], ['Idempotency-Key' => 'six-installments'])
            ->assertCreated()
            ->assertJsonCount(6, 'data.installments');

        $installments = collect($response->json('data.installments'));
        $this->assertSame([1, 2, 3, 4, 5, 6], $installments->pluck('sequence')->all());
        $this->assertSame(120_000, $installments->sum(fn (array $installment) => $installment['amount']['amount_centavos']));
        $this->assertSame([
            '2026-09-10',
            '2026-10-10',
            '2026-11-10',
            '2026-12-10',
            '2027-01-10',
            '2027-02-10',
        ], $installments->pluck('statement.closing_date')->all());
    }

    #[Test]
    public function uneven_installments_put_the_remainder_on_the_earliest_installments(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user);

        $response = $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', [
            'category_id' => $this->expenseCategory($user)->id,
            'description' => 'R$ 100,00 em três',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 10_000,
            'installment_count' => 3,
        ], ['Idempotency-Key' => 'uneven-installments'])
            ->assertCreated();

        $this->assertSame([3_334, 3_333, 3_333], collect($response->json('data.installments'))
            ->pluck('amount.amount_centavos')
            ->all());
        $this->assertSame(10_000, (int) CreditCardInstallment::query()->sum('amount_centavos'));
    }

    #[Test]
    public function full_purchase_total_reserves_card_limit_even_when_unbilled(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user, ['credit_limit_centavos' => 100_000]);

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', [
            'category_id' => $this->expenseCategory($user)->id,
            'description' => 'Compra em 4x',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 80_000,
            'installment_count' => 4,
        ], ['Idempotency-Key' => 'reserve-full'])->assertCreated();

        $summary = $this->cardSummary($card->refresh());
        $this->assertSame(80_000, $summary['used_credit_centavos']);
        $this->assertSame(20_000, $summary['available_credit_centavos']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidPurchaseCases(): array
    {
        return [
            'zero amount' => [['total_amount_centavos' => 0], 'total_amount_centavos'],
            'negative amount' => [['total_amount_centavos' => -100], 'total_amount_centavos'],
            'zero installments' => [['installment_count' => 0], 'installment_count'],
            'too many installments' => [['installment_count' => 361], 'installment_count'],
            'installments above centavos' => [['total_amount_centavos' => 3, 'installment_count' => 4], 'installment_count'],
            'blank description' => [['description' => ''], 'description'],
            'invalid date' => [['purchase_date' => '2026-02-30'], 'purchase_date'],
            'date before range' => [['purchase_date' => '1899-12-31'], 'purchase_date'],
            'date after range' => [['purchase_date' => '2101-01-01'], 'purchase_date'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPurchaseCases')]
    public function invalid_purchase_input_is_rejected(array $overrides, string $field): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user);

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', array_merge([
            'category_id' => $this->expenseCategory($user)->id,
            'description' => 'Compra',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 10_000,
            'installment_count' => 1,
        ], $overrides), ['Idempotency-Key' => 'invalid-'.$field])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame(0, CreditCardPurchase::count());
    }

    #[Test]
    public function foreign_income_and_archived_categories_are_rejected(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user);
        $income = Category::factory()->create([
            'user_id' => $user->id,
            'classification' => CategoryClassification::Income,
        ]);
        $archived = $this->expenseCategory($user, ['status' => CategoryStatus::Archived, 'archived_at' => now()]);

        foreach ([$income->id => 'income', $archived->id => 'archived'] as $categoryId => $label) {
            $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', [
                'category_id' => $categoryId,
                'description' => 'Compra',
                'purchase_date' => '2026-09-05',
                'total_amount_centavos' => 1_000,
                'installment_count' => 1,
            ], ['Idempotency-Key' => 'category-'.$label])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('category_id');
        }

        $this->assertSame(0, CreditCardPurchase::count());
    }

    #[Test]
    public function repeated_purchase_submission_replays_without_duplicating_installments(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user);
        $payload = [
            'category_id' => $this->expenseCategory($user)->id,
            'description' => 'Compra idempotente',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 50_000,
            'installment_count' => 5,
        ];

        $first = $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', $payload, ['Idempotency-Key' => 'purchase-replay'])
            ->assertCreated();

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', $payload, ['Idempotency-Key' => 'purchase-replay'])
            ->assertCreated()
            ->assertExactJson($first->json());

        $this->assertSame(1, CreditCardPurchase::count());
        $this->assertSame(5, CreditCardInstallment::count());
    }

    #[Test]
    public function reusing_a_key_for_a_different_purchase_is_rejected(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user);
        $category = $this->expenseCategory($user);
        $payload = [
            'category_id' => $category->id,
            'description' => 'Compra',
            'purchase_date' => '2026-09-05',
            'total_amount_centavos' => 10_000,
            'installment_count' => 1,
        ];

        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', $payload, ['Idempotency-Key' => 'purchase-conflict'])->assertCreated();
        $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', array_merge($payload, ['total_amount_centavos' => 20_000]), ['Idempotency-Key' => 'purchase-conflict'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_key_reused');
    }

    #[Test]
    public function installment_assignments_walk_month_and_year_boundaries(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user, ['closing_day' => 31, 'due_day' => 31]);

        $response = $this->postJson('/api/v1/credit-cards/'.$card->id.'/purchases', [
            'category_id' => $this->expenseCategory($user)->id,
            'description' => 'Fronteira de ano',
            'purchase_date' => '2026-12-15',
            'total_amount_centavos' => 40_000,
            'installment_count' => 3,
        ], ['Idempotency-Key' => 'year-boundary'])
            ->assertCreated();

        $this->assertSame(['2026-12-31', '2027-01-31', '2027-02-28'], collect($response->json('data.installments'))
            ->pluck('statement.closing_date')
            ->all());
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], collect($response->json('data.installments'))
            ->pluck('statement.due_date')
            ->all());
    }
}
