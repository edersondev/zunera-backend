<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\Categories\CategoryClassification;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\NotificationEvent;
use App\Services\Notifications\NotificationFactDispatcher;
use App\Services\Notifications\NotificationReconciler;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\RecurringTransactions\RecurringTransactionFeatureTestCase;
use Tests\Support\CreditCards\CreditCardFixtures;

final class NotificationExclusionTest extends RecurringTransactionFeatureTestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function ordinary_transaction_create_edit_transfer_and_report_read_emit_no_event(): void
    {
        $user = $this->signInUser();
        $source = FinancialAccount::factory()->create([
            'user_id' => $user->id, 'initial_balance_centavos' => 100000, 'current_balance_centavos' => 100000,
        ]);
        $destination = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $expense = Category::factory()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Expense]);

        $id = $this->postJson('/api/v1/transactions', [
            'financial_account_id' => $source->id, 'category_id' => $expense->id, 'type' => 'expense',
            'description' => 'Ordinary purchase', 'amount_centavos' => 1000, 'transaction_date' => today()->toDateString(),
        ], ['Idempotency-Key' => 'exclusion-transaction'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/transactions/{$id}", ['description' => 'Edited purchase'],
            ['Idempotency-Key' => 'exclusion-transaction-edit'])->assertOk();
        $this->postJson('/api/v1/transfers', [
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 500,
            'transfer_date' => today()->toDateString(),
            'description' => 'Between accounts',
        ], ['Idempotency-Key' => 'exclusion-transfer'])->assertCreated();
        $this->getJson('/api/v1/financial-reports')->assertOk();
        $this->getJson('/api/v1/financial-reports/contributions?metric=realized_expenses')->assertOk();
        $this->drain();
        self::assertSame(0, NotificationEvent::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function successful_statement_payment_has_no_payment_event(): void
    {
        $user = $this->cardSignIn('2026-09-18');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17, 'credit_limit_centavos' => 500000]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 10000, 1, '2026-08-05');
        $statement = $purchase->installments()->first()->statement;
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 20000, 'current_balance_centavos' => 20000]);
        $this->postJson("/api/v1/credit-card-statements/{$statement->id}/payments", [
            'financial_account_id' => $account->id,
            'amount_centavos' => 10000,
            'payment_date' => '2026-09-18',
        ], ['Idempotency-Key' => 'exclusion-statement-payment'])->assertCreated();
        $this->drain();
        self::assertSame(0, NotificationEvent::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function automatic_card_generation_has_no_review_event(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $rule = $this->cardRule($user, $card, [
            'start_date' => '2026-09-24', 'eligibility_starts_on' => '2026-09-24', 'schedule_cursor' => '2026-09-24',
        ]);
        app(RecurringOccurrenceService::class)->processRule((int) $rule->id, '2026-09-24');
        $this->drain();
        self::assertSame(CardOccurrenceState::Recorded, $rule->cardOccurrences()->firstOrFail()->state);
        self::assertSame(0, NotificationEvent::query()->where('type', 'recurrence_review')->count());
    }

    #[Test]
    public function below_target_goal_contribution_and_unspent_budget_edit_emit_no_event(): void
    {
        $user = $this->signInUser();
        $account = FinancialAccount::factory()->create([
            'user_id' => $user->id, 'initial_balance_centavos' => 1000, 'current_balance_centavos' => 1000,
        ]);
        $goalId = $this->postJson('/api/v1/financial-goals', [
            'name' => 'Reserve', 'target_centavos' => 100, 'financial_account_id' => $account->id,
            'initial_allocated_centavos' => 20,
        ], ['Idempotency-Key' => 'exclusion-goal'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/financial-goals/{$goalId}/allocations", ['amount_centavos' => 10],
            ['Idempotency-Key' => 'exclusion-contribution'])->assertOk();

        $category = Category::factory()->create(['user_id' => $user->id, 'classification' => CategoryClassification::Expense]);
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => (int) now('America/Sao_Paulo')->year,
            'month' => (int) now('America/Sao_Paulo')->month])->assertCreated()->json('data.budget.id');
        $planId = $this->postJson("/api/v1/budgets/{$budgetId}/plans", [
            'category_id' => $category->id, 'planned_amount_centavos' => 10000,
        ])->assertCreated()->json('data.budget.plans.0.id');
        $this->patchJson("/api/v1/budgets/{$budgetId}/plans/{$planId}", [
            'planned_amount_centavos' => 12000,
        ])->assertOk();
        $this->drain();
        self::assertSame(0, NotificationEvent::query()->where('user_id', $user->id)->count());
    }

    private function drain(): void
    {
        $result = app(NotificationReconciler::class)->drain(app(NotificationFactDispatcher::class)->handle(...));
        self::assertSame(0, $result['failed']);
    }
}
