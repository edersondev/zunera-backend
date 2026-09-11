<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Enums\Categories\CategoryClassification;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

abstract class TransactionFeatureTestCase extends TestCase
{
    protected function signInUser(): User
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        return $user;
    }

    /** @return array{0: FinancialAccount, 1: Category} */
    protected function associations(User $user, CategoryClassification $classification = CategoryClassification::Expense): array
    {
        return [
            FinancialAccount::factory()->create(['user_id' => $user->id]),
            Category::factory()->create(['user_id' => $user->id, 'classification' => $classification]),
        ];
    }

    protected function transaction(User $user, string $type = 'expense', array $attributes = []): Transaction
    {
        [$account, $category] = $this->associations($user, $type === 'income' ? CategoryClassification::Income : CategoryClassification::Expense);

        return Transaction::factory()->create(array_merge([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => $type,
        ], $attributes));
    }
}
