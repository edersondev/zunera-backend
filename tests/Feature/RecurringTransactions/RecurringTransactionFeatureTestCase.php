<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\Categories\CategoryClassification;
use App\Enums\CreditCards\CreditCardStatus;
use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\RecurrenceDestinationType;
use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionType;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Services\RecurringTransactions\RecurringDateRange;
use Tests\TestCase;

abstract class RecurringTransactionFeatureTestCase extends TestCase
{
    protected function signInUser(): User
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        return $user;
    }

    protected function ownedAccount(User $user, int $balance = 500_000): FinancialAccount
    {
        return FinancialAccount::factory()->create([
            'user_id' => $user->id,
            'initial_balance_centavos' => $balance,
            'current_balance_centavos' => $balance,
        ]);
    }

    protected function ownedCategory(User $user, TransactionType $type = TransactionType::Expense): Category
    {
        return Category::factory()->create([
            'user_id' => $user->id,
            'classification' => $type === TransactionType::Income
                ? CategoryClassification::Income
                : CategoryClassification::Expense,
        ]);
    }

    protected function ownedCard(User $user, int $limit = 1_000_000): CreditCard
    {
        return CreditCard::factory()->create([
            'user_id' => $user->id,
            'credit_limit_centavos' => $limit,
            'status' => CreditCardStatus::Active,
        ]);
    }

    /** @return array<string, mixed> */
    protected function payload(FinancialAccount $account, Category $category, array $overrides = []): array
    {
        return array_merge([
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => TransactionType::Expense->value,
            'amount_centavos' => 25_000,
            'description' => 'Assinatura mensal',
            'notes' => 'Plano família',
            'frequency' => RecurrenceFrequency::Monthly->value,
            'start_date' => RecurringDateRange::businessDate(),
            'end_date' => null,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function cardPayload(CreditCard $card, Category $category, array $overrides = []): array
    {
        return array_merge([
            'destination_type' => RecurrenceDestinationType::CreditCard->value,
            'credit_card_id' => $card->id,
            'generation_mode' => CardGenerationMode::Automatic->value,
            'category_id' => $category->id,
            'type' => TransactionType::Expense->value,
            'amount_centavos' => 15_000,
            'description' => 'Academia mensal',
            'notes' => null,
            'frequency' => RecurrenceFrequency::Monthly->value,
            'start_date' => RecurringDateRange::businessDate(),
            'end_date' => null,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    protected function rule(User $user, array $overrides = []): RecurringTransaction
    {
        $account = $overrides['financial_account_id'] ?? null;
        $category = $overrides['category_id'] ?? null;
        unset($overrides['financial_account_id'], $overrides['category_id']);

        $accountModel = $account !== null
            ? FinancialAccount::query()->find($account)
            : $this->ownedAccount($user);
        $type = $overrides['type'] ?? TransactionType::Expense;
        $categoryModel = $category !== null
            ? Category::query()->find($category)
            : $this->ownedCategory($user, $type instanceof TransactionType ? $type : TransactionType::Expense);

        return RecurringTransaction::factory()->create(array_merge([
            'user_id' => $user->id,
            'financial_account_id' => $accountModel?->id,
            'category_id' => $categoryModel?->id,
            'type' => TransactionType::Expense,
            'frequency' => RecurrenceFrequency::Monthly,
            'state' => RecurrenceState::Active,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    protected function cardRule(User $user, CreditCard $card, array $overrides = []): RecurringTransaction
    {
        $category = $overrides['category_id'] ?? null;
        unset($overrides['category_id']);
        $categoryModel = $category !== null
            ? Category::query()->find($category)
            : $this->ownedCategory($user, TransactionType::Expense);

        return RecurringTransaction::factory()->card($card, $overrides['generation_mode'] ?? null)->create(array_merge([
            'user_id' => $user->id,
            'category_id' => $categoryModel->id,
            'type' => TransactionType::Expense,
            'frequency' => RecurrenceFrequency::Monthly,
            'state' => RecurrenceState::Active,
        ], $overrides));
    }
}
