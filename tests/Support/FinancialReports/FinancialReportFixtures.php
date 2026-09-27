<?php

declare(strict_types=1);

namespace Tests\Support\FinancialReports;

use App\Enums\Categories\CategoryClassification;
use App\Enums\RecurringTransactions\RecurrenceDestinationType;
use App\Enums\Transactions\TransactionStatus;
use App\Enums\Transactions\TransactionType;
use App\Models\Category;
use App\Models\CreditCardCreditEvent;
use App\Models\CreditCardPurchase;
use App\Models\FinancialAccount;
use App\Models\FinancialGoal;
use App\Models\FinancialGoalActivity;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\CreditCards\CreditCardFixtures;

/** Owned source-record builders shared by report contract and reconciliation tests. */
trait FinancialReportFixtures
{
    use CreditCardFixtures;

    protected function reportSignIn(string $date = '2026-09-26', ?User $user = null): User
    {
        return $this->cardSignIn($date, $user);
    }

    /** @param array<string, mixed> $attributes */
    protected function reportAccount(User $user, array $attributes = []): FinancialAccount
    {
        return FinancialAccount::factory()->create(array_merge($attributes, ['user_id' => $user->id]));
    }

    /** @param array<string, mixed> $attributes */
    protected function reportCategory(User $user, string $type = 'expense', array $attributes = []): Category
    {
        return Category::factory()->create(array_merge([
            'user_id' => $user->id,
            'classification' => $type === 'income' ? CategoryClassification::Income : CategoryClassification::Expense,
        ], $attributes, ['user_id' => $user->id]));
    }

    /** @param array<string, mixed> $attributes */
    protected function reportTransaction(
        User $user,
        FinancialAccount $account,
        Category $category,
        int $amountCentavos,
        string $date,
        array $attributes = [],
    ): Transaction {
        return Transaction::factory()->create(array_merge([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => $category->classification === CategoryClassification::Income
                ? TransactionType::Income : TransactionType::Expense,
            'status' => TransactionStatus::Effective,
            'amount_centavos' => $amountCentavos,
            'transaction_date' => $date,
        ], $attributes, ['user_id' => $user->id]));
    }

    /** @param array<string, mixed> $attributes */
    protected function reportTransfer(
        User $user,
        FinancialAccount $source,
        FinancialAccount $destination,
        int $amountCentavos,
        string $date,
        array $attributes = [],
    ): Transfer {
        return Transfer::factory()->create(array_merge([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => $amountCentavos,
            'transfer_date' => $date,
        ], $attributes, ['user_id' => $user->id]));
    }

    protected function reportCardPurchase(
        User $user,
        Category $category,
        int $amountCentavos,
        int $installments,
        string $purchaseDate,
    ): CreditCardPurchase {
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);

        return $this->recordPurchase($card, $category, $amountCentavos, $installments, $purchaseDate);
    }

    /** Record a refund through the public idempotent command after paying its source statement. */
    protected function reportPaidRefund(
        CreditCardPurchase $purchase,
        FinancialAccount $paymentAccount,
        int $refundCentavos,
        string $paymentDate,
        string $refundDate,
        string $key,
    ): CreditCardCreditEvent {
        $statement = $purchase->installments()->firstOrFail()->statement;
        $this->payStatement($statement, $paymentAccount, $statement->outstandingCentavos(), $paymentDate);

        $this->postJson('/api/v1/credit-card-purchases/'.$purchase->id.'/credit-events', [
            'reason' => 'refund',
            'amount_centavos' => $refundCentavos,
            'event_date' => $refundDate,
        ], ['Idempotency-Key' => $key])->assertCreated();

        return CreditCardCreditEvent::query()
            ->where('credit_card_purchase_id', $purchase->id)
            ->latest('id')
            ->firstOrFail();
    }

    protected function reportGoalActivity(User $user, FinancialAccount $account, string $type, int $amountCentavos, string $date): FinancialGoalActivity
    {
        $goal = FinancialGoal::create([
            'user_id' => $user->id,
            'name' => 'Report fixture goal',
            'target_centavos' => 100_000,
            'financial_account_id' => $account->id,
            'account_name_snapshot' => $account->name,
        ]);

        return FinancialGoalActivity::create([
            'financial_goal_id' => $goal->id,
            'user_id' => $user->id,
            'type' => $type,
            'amount_centavos' => $amountCentavos,
            'financial_account_id_at_time' => $account->id,
            'account_name_at_time' => $account->name,
            'occurred_at' => CarbonImmutable::parse($date.' 10:00:00', 'America/Sao_Paulo'),
            'business_date' => $date,
        ]);
    }

    /** A generated ordinary occurrence is represented only by its transaction. */
    protected function reportRecurringOccurrence(
        User $user,
        FinancialAccount $account,
        Category $category,
        int $amountCentavos,
        string $scheduledDate,
        TransactionStatus $status = TransactionStatus::Pending,
    ): Transaction {
        $rule = RecurringTransaction::factory()->create([
            'user_id' => $user->id,
            'destination_type' => RecurrenceDestinationType::FinancialAccount,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => $category->classification === CategoryClassification::Income
                ? TransactionType::Income : TransactionType::Expense,
            'amount_centavos' => $amountCentavos,
            'start_date' => $scheduledDate,
            'eligibility_starts_on' => $scheduledDate,
            'schedule_cursor' => $scheduledDate,
        ]);

        return $this->reportTransaction($user, $account, $category, $amountCentavos, $scheduledDate, [
            'status' => $status,
            'recurring_transaction_id' => $rule->id,
            'recurrence_scheduled_date' => $scheduledDate,
        ]);
    }
}
