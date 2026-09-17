<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\Transactions\TransactionType;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Services\RecurringTransactions\RecurringDateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class RecurringTransactionValidationTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function amount_description_note_and_date_rules_are_validated(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);

        $base = $this->payload($account, $category);
        $cases = [
            ['amount_centavos' => 0, 'field' => 'amount_centavos'],
            ['amount_centavos' => RecurringTransaction::MAX_AMOUNT_CENTAVOS + 1, 'field' => 'amount_centavos'],
            ['description' => '   ', 'field' => 'description'],
            ['description' => str_repeat('a', 201), 'field' => 'description'],
            ['notes' => str_repeat('a', 1001), 'field' => 'notes'],
            ['start_date' => '1899-12-31', 'field' => 'start_date'],
            ['start_date' => '2101-01-01', 'field' => 'start_date'],
            ['start_date' => '2026-02-30', 'field' => 'start_date'],
            ['start_date' => 'not-a-date', 'field' => 'start_date'],
            ['frequency' => 'daily', 'field' => 'frequency'],
            ['end_date' => '2025-01-01', 'field' => 'end_date'],
        ];

        foreach ($cases as $index => $case) {
            $field = $case['field'];
            unset($case['field']);
            $this->postJson('/api/v1/recurring-transactions', array_merge($base, $case), ['Idempotency-Key' => 'invalid-'.$index])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        // End date before start date is rejected after both dates are normalized.
        $this->postJson('/api/v1/recurring-transactions', array_merge($base, [
            'start_date' => '2030-05-10',
            'end_date' => '2030-05-09',
        ]), ['Idempotency-Key' => 'invalid-range'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');

        $this->postJson('/api/v1/recurring-transactions', $base, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        self::assertSame(0, RecurringTransaction::count());
    }

    #[Test]
    public function associations_must_be_owned_active_and_type_matched(): void
    {
        $user = $this->signInUser();
        $stranger = User::factory()->create();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $archivedAccount = FinancialAccount::factory()->archived()->create(['user_id' => $user->id]);
        $archivedCategory = Category::factory()->archived()->create(['user_id' => $user->id]);
        $foreignAccount = FinancialAccount::factory()->create(['user_id' => $stranger->id]);
        $foreignCategory = Category::factory()->create(['user_id' => $stranger->id]);
        $incomeCategory = $this->ownedCategory($user, TransactionType::Income);

        $this->postJson('/api/v1/recurring-transactions', $this->payload($archivedAccount, $category), ['Idempotency-Key' => 'archived-account'])
            ->assertUnprocessable()->assertJsonValidationErrors('financial_account_id');
        $this->postJson('/api/v1/recurring-transactions', $this->payload($account, $archivedCategory), ['Idempotency-Key' => 'archived-category'])
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->postJson('/api/v1/recurring-transactions', $this->payload($foreignAccount, $category), ['Idempotency-Key' => 'foreign-account'])
            ->assertNotFound();
        $this->postJson('/api/v1/recurring-transactions', $this->payload($account, $foreignCategory), ['Idempotency-Key' => 'foreign-category'])
            ->assertNotFound();
        $this->postJson('/api/v1/recurring-transactions', $this->payload($account, $incomeCategory), ['Idempotency-Key' => 'mismatch'])
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');

        self::assertSame(0, RecurringTransaction::count());
    }

    #[Test]
    public function list_rejects_foreign_or_missing_filter_associations_with_privacy_safe_404(): void
    {
        $user = $this->signInUser();
        $stranger = User::factory()->create();
        $foreignAccount = FinancialAccount::factory()->create(['user_id' => $stranger->id]);
        $foreignCategory = Category::factory()->create(['user_id' => $stranger->id]);

        $this->getJson('/api/v1/recurring-transactions?financial_account_id='.$foreignAccount->id)->assertNotFound();
        $this->getJson('/api/v1/recurring-transactions?financial_account_id=999999')->assertNotFound();
        $this->getJson('/api/v1/recurring-transactions?category_id='.$foreignCategory->id)->assertNotFound();
        $this->getJson('/api/v1/recurring-transactions?per_page=51')->assertUnprocessable();
        $this->getJson('/api/v1/recurring-transactions?state=archived')->assertUnprocessable();
    }

    #[Test]
    public function list_accepts_archived_owned_association_filters(): void
    {
        $user = $this->signInUser();
        $archivedAccount = FinancialAccount::factory()->archived()->create(['user_id' => $user->id]);
        $rule = $this->rule($user, ['financial_account_id' => $archivedAccount->id]);

        $response = $this->getJson('/api/v1/recurring-transactions?financial_account_id='.$archivedAccount->id)->assertOk();
        self::assertSame(1, $response->json('meta.total'));
        self::assertSame($rule->id, $response->json('data.0.id'));
        self::assertSame(RecurringDateRange::businessDate(), RecurringDateRange::businessDate());
    }
}
