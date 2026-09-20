<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\Category;
use App\Models\MonthlyBudget;
use App\Services\CreditCards\InstallmentAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardBudgetIntegrationTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    /** @return array<string, array{0: int, 1: int}> */
    public static function purchasePaymentPairs(): array
    {
        $cases = [];
        foreach ([10_000, 33_300, 99_999, 1, 5_00, 12_345] as $total) {
            foreach ([1, 2, 3, 6, 12] as $installments) {
                if ($installments > $total) {
                    continue;
                }
                $cases["{$total} in {$installments}"] = [$total, $installments];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('purchasePaymentPairs')]
    public function card_installments_are_expected_while_open_and_realized_once_after_closing(int $total, int $installmentCount): void
    {
        // Business date sits inside the October cycle, one day before closing.
        $user = $this->cardSignIn('2026-10-09');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17, 'credit_limit_centavos' => 99_999_999_999]);
        $category = $this->expenseCategory($user);
        $budget = MonthlyBudget::factory()->create(['user_id' => $user->id, 'budget_year' => 2026, 'budget_month' => 10]);
        $budget->plans()->create($this->planAttributes($category, 500_000));

        $firstStatementAmount = app(InstallmentAllocator::class)->allocate($total, $installmentCount)[0];
        $purchase = $this->recordPurchase($card, $category, $total, $installmentCount, '2026-10-09');
        $this->assertSame($firstStatementAmount, (int) $purchase->installments()->orderBy('sequence')->value('amount_centavos'));

        // Still open: the first installment is expected, never realized.
        $open = $this->getJson('/api/v1/budgets/2026/10')->assertOk();
        $plannedOctober = collect($open->json('data.budget.plans'))->firstWhere('category.id', $category->id);
        $this->assertSame(0, $plannedOctober['realized']['amount_centavos']);
        $this->assertSame($firstStatementAmount, $plannedOctober['expected']['amount_centavos']);

        // After closing: realized exactly once and no longer expected.
        $this->cardSignIn('2026-10-11', $user);
        $closed = $this->getJson('/api/v1/budgets/2026/10')->assertOk();
        $plannedOctober = collect($closed->json('data.budget.plans'))->firstWhere('category.id', $category->id);
        $this->assertSame($firstStatementAmount, $plannedOctober['realized']['amount_centavos']);
        $this->assertSame(0, $plannedOctober['expected']['amount_centavos']);
    }

    #[Test]
    public function statement_payment_never_consumes_budget_again(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $category = $this->expenseCategory($user);
        $budget = MonthlyBudget::factory()->create(['user_id' => $user->id, 'budget_year' => 2026, 'budget_month' => 8]);
        $budget->plans()->create($this->planAttributes($category, 100_000));

        $purchase = $this->recordPurchase($card, $category, 60_000, 1, '2026-08-05');
        $before = $this->getJson('/api/v1/budgets/2026/8')->assertOk();
        $realizedBefore = collect($before->json('data.budget.plans'))->firstWhere('category.id', $category->id)['realized']['amount_centavos'];
        $this->assertSame(60_000, $realizedBefore);

        $account = $this->cardAccount($user, ['initial_balance_centavos' => 500_000, 'current_balance_centavos' => 500_000]);
        $this->postJson('/api/v1/credit-card-statements/'.$purchase->installments()->first()->credit_card_statement_id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 60_000,
            'payment_date' => '2026-08-15',
        ], ['Idempotency-Key' => 'budget-payment'])->assertCreated();

        $after = $this->getJson('/api/v1/budgets/2026/8')->assertOk();
        $realizedAfter = collect($after->json('data.budget.plans'))->firstWhere('category.id', $category->id)['realized']['amount_centavos'];
        $this->assertSame(60_000, $realizedAfter, 'Payment must not add a second expense.');
        $this->assertSame(440_000, $account->refresh()->current_balance_centavos);
    }

    #[Test]
    public function credit_events_reduce_recognized_spending_exactly_once(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $category = $this->expenseCategory($user);
        $budget = MonthlyBudget::factory()->create(['user_id' => $user->id, 'budget_year' => 2026, 'budget_month' => 8]);
        $budget->plans()->create($this->planAttributes($category, 100_000));
        $purchase = $this->recordPurchase($card, $category, 40_000, 1, '2026-08-05');

        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => 15_000,
            'event_date' => '2026-09-19',
        ], ['Idempotency-Key' => 'budget-refund'])->assertCreated();

        $budgetResponse = $this->getJson('/api/v1/budgets/2026/8')->assertOk();
        $planned = collect($budgetResponse->json('data.budget.plans'))->firstWhere('category.id', $category->id);
        $this->assertSame(25_000, $planned['realized']['amount_centavos']);
    }

    #[Test]
    public function unbudgeted_card_spending_stays_visible_without_duplicating(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $budgeted = $this->expenseCategory($user, ['name' => 'Mercado']);
        $unbudgeted = $this->expenseCategory($user, ['name' => 'Lazer']);
        $budget = MonthlyBudget::factory()->create(['user_id' => $user->id, 'budget_year' => 2026, 'budget_month' => 8]);
        $budget->plans()->create($this->planAttributes($budgeted, 100_000));

        $this->recordPurchase($card, $budgeted, 30_000, 1, '2026-08-05');
        $this->recordPurchase($card, $unbudgeted, 20_000, 1, '2026-08-06');

        $response = $this->getJson('/api/v1/budgets/2026/8')->assertOk();
        $this->assertSame(20_000, $response->json('data.budget.summary.unbudgeted_expenses.amount_centavos'));
        $this->assertSame(50_000, $response->json('data.budget.summary.total_expenses.amount_centavos'));
    }

    /** @return array<string, mixed> */
    private function planAttributes(Category $category, int $plannedCentavos): array
    {
        return [
            'category_id' => $category->id,
            'planned_amount_centavos' => $plannedCentavos,
            'category_name_snapshot' => $category->name,
            'category_classification_snapshot' => $category->classification->value,
            'category_origin_snapshot' => $category->origin->value,
            'category_color_snapshot' => $category->color,
            'category_icon_snapshot' => $category->icon,
        ];
    }
}
