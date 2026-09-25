<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Models\Category;
use App\Models\MonthlyBudget;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\RecurringTransactions\RecurringTransactionFeatureTestCase;
use Tests\Support\CreditCards\CreditCardFixtures;

final class RecurringCardBudgetRecognitionTest extends RecurringTransactionFeatureTestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function one_recurring_installment_moves_from_expected_to_realized_without_payment_double_count(): void
    {
        $user = $this->cardSignIn('2026-09-24');
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $account = $this->ownedAccount($user, 100_000);
        $budget = MonthlyBudget::factory()->create(['user_id' => $user->id, 'budget_year' => 2026, 'budget_month' => 10]);
        $budget->plans()->create($this->planAttributes($category));
        $september = MonthlyBudget::factory()->create(['user_id' => $user->id, 'budget_year' => 2026, 'budget_month' => 9]);
        $september->plans()->create($this->planAttributes($category));
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-24',
            'eligibility_starts_on' => '2026-09-24',
            'schedule_cursor' => '2026-09-24',
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24');
        $purchase = $rule->cardOccurrences()->firstOrFail()->purchase;
        $statement = $purchase->installments()->firstOrFail()->statement;
        self::assertSame('2026-10-10', $statement->closing_date->toDateString());

        $open = $this->getJson('/api/v1/budgets/2026/10')->assertOk();
        $plan = collect($open->json('data.budget.plans'))->firstWhere('category.id', $category->id);
        self::assertSame(15_000, $plan['expected']['amount_centavos']);
        self::assertSame(0, $plan['realized']['amount_centavos']);
        $septemberResponse = $this->getJson('/api/v1/budgets/2026/9')->assertOk();
        self::assertSame(0, $septemberResponse->json('data.budget.summary.total_expenses.amount_centavos'));

        $this->cardSignIn('2026-10-11', $user);
        $closed = $this->getJson('/api/v1/budgets/2026/10')->assertOk();
        $plan = collect($closed->json('data.budget.plans'))->firstWhere('category.id', $category->id);
        self::assertSame(0, $plan['expected']['amount_centavos']);
        self::assertSame(15_000, $plan['realized']['amount_centavos']);

        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => 5_000,
            'event_date' => '2026-10-11',
        ], ['Idempotency-Key' => 'recurring-budget-refund'])->assertCreated();
        $refunded = $this->getJson('/api/v1/budgets/2026/10')->assertOk();
        $plan = collect($refunded->json('data.budget.plans'))->firstWhere('category.id', $category->id);
        self::assertSame(10_000, $plan['realized']['amount_centavos']);

        $this->cardSignIn('2026-10-18', $user);
        $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 10_000,
            'payment_date' => '2026-10-17',
        ], ['Idempotency-Key' => 'recurring-budget-payment'])->assertCreated();
        $paid = $this->getJson('/api/v1/budgets/2026/10')->assertOk();
        $plan = collect($paid->json('data.budget.plans'))->firstWhere('category.id', $category->id);
        self::assertSame(10_000, $plan['realized']['amount_centavos']);
        self::assertSame(90_000, $account->fresh()->current_balance_centavos);
        self::assertSame(1, $rule->cardOccurrences()->count());
    }

    #[Test]
    public function open_purchase_correction_moves_budget_recognition_to_its_new_category_once(): void
    {
        $user = $this->cardSignIn('2026-09-24');
        $card = $this->ownedCard($user);
        $original = $this->ownedCategory($user);
        $replacement = $this->ownedCategory($user);
        $budget = MonthlyBudget::factory()->create(['user_id' => $user->id, 'budget_year' => 2026, 'budget_month' => 10]);
        $budget->plans()->create($this->planAttributes($original));
        $budget->plans()->create($this->planAttributes($replacement));
        $rule = $this->cardRule($user, $card, [
            'category_id' => $original->id,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-24',
            'eligibility_starts_on' => '2026-09-24',
            'schedule_cursor' => '2026-09-24',
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24');
        $occurrence = $rule->cardOccurrences()->firstOrFail();
        $purchase = $occurrence->purchase;

        $this->patchJson('/api/v1/credit-card-purchases/'.$purchase->id, [
            'category_id' => $replacement->id,
            'total_amount_centavos' => 20_000,
        ], ['Idempotency-Key' => 'recurring-budget-correction'])->assertOk();

        $response = $this->getJson('/api/v1/budgets/2026/10')->assertOk();
        $plans = collect($response->json('data.budget.plans'));
        self::assertSame(0, $plans->firstWhere('category.id', $original->id)['expected']['amount_centavos']);
        self::assertSame(20_000, $plans->firstWhere('category.id', $replacement->id)['expected']['amount_centavos']);
        self::assertSame($occurrence->id, $purchase->fresh()->recurring_card_occurrence_id);
        self::assertSame($original->id, $occurrence->fresh()->category_id_original);
    }

    #[Test]
    public function confirmation_expectation_does_not_enter_budget_before_purchase(): void
    {
        $user = $this->cardSignIn('2026-09-24');
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $budget = MonthlyBudget::factory()->create(['user_id' => $user->id, 'budget_year' => 2026, 'budget_month' => 10]);
        $budget->plans()->create($this->planAttributes($category));
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'generation_mode' => CardGenerationMode::Confirmation,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-24',
            'eligibility_starts_on' => '2026-09-24',
            'schedule_cursor' => '2026-09-24',
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24');

        $response = $this->getJson('/api/v1/budgets/2026/10')->assertOk();
        $plan = collect($response->json('data.budget.plans'))->firstWhere('category.id', $category->id);
        self::assertSame(0, $plan['expected']['amount_centavos']);
        self::assertSame(0, $plan['realized']['amount_centavos']);
        self::assertNull($rule->cardOccurrences()->firstOrFail()->purchase);
    }

    /** @return array<string, mixed> */
    private function planAttributes(Category $category): array
    {
        return [
            'category_id' => $category->id,
            'planned_amount_centavos' => 100_000,
            'category_name_snapshot' => $category->name,
            'category_classification_snapshot' => $category->classification->value,
            'category_origin_snapshot' => $category->origin->value,
            'category_color_snapshot' => $category->color,
            'category_icon_snapshot' => $category->icon,
        ];
    }
}
