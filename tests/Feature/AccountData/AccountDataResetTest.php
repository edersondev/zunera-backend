<?php

declare(strict_types=1);

namespace Tests\Feature\AccountData;

use App\Models\BudgetCategoryPlan;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardCreditApplication;
use App\Models\CreditCardCreditEvent;
use App\Models\CreditCardInstallment;
use App\Models\CreditCardPurchase;
use App\Models\CreditCardStatement;
use App\Models\CreditCardStatementPayment;
use App\Models\FinancialAccount;
use App\Models\MonthlyBudget;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AccountDataResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173',
        ]);
    }

    #[Test]
    public function archive_preserves_read_only_relationships_and_clears_live_finances(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $destination = FinancialAccount::factory()->archived()->create(['user_id' => $user->id]);
        $otherAccount = FinancialAccount::factory()->create(['user_id' => $other->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $systemCategory = Category::factory()->system()->create();
        Transaction::factory()->create(['user_id' => $user->id, 'financial_account_id' => $account->id, 'category_id' => $category->id]);
        Transfer::factory()->create(['user_id' => $user->id, 'source_financial_account_id' => $account->id, 'destination_financial_account_id' => $destination->id]);
        RecurringTransaction::factory()->create(['user_id' => $user->id, 'financial_account_id' => $account->id, 'category_id' => $category->id]);
        $budget = MonthlyBudget::factory()->create(['user_id' => $user->id]);
        BudgetCategoryPlan::factory()->create(['monthly_budget_id' => $budget->id, 'category_id' => $category->id]);
        $card = CreditCard::factory()->create(['user_id' => $user->id]);
        $purchase = CreditCardPurchase::factory()->forCard($card)->withCategory($category)->create();
        $statement = CreditCardStatement::factory()->forCard($card)->create();
        $installment = CreditCardInstallment::factory()->forPurchase($purchase, $statement)->create();
        CreditCardStatementPayment::factory()->create(['user_id' => $user->id, 'credit_card_statement_id' => $statement->id, 'credit_card_id' => $card->id, 'financial_account_id' => $account->id]);
        $event = CreditCardCreditEvent::factory()->forPurchase($purchase, 500)->create();
        CreditCardCreditApplication::factory()->create(['user_id' => $user->id, 'credit_card_credit_event_id' => $event->id, 'credit_card_id' => $card->id, 'credit_card_installment_id' => $installment->id, 'credit_card_statement_id' => $statement->id]);
        $cardRule = RecurringTransaction::factory()->card($card)->create(['category_id' => $category->id]);
        $occurrenceId = DB::table('recurring_card_occurrences')->insertGetId([
            'user_id' => $user->id,
            'recurring_transaction_id' => $cardRule->id,
            'scheduled_date' => today()->toDateString(),
            'generation_mode_snapshot' => 'automatic',
            'scheduled_amount_centavos' => 1000,
            'description_snapshot' => 'Recurring purchase',
            'category_id_original' => $category->id,
            'credit_card_id_original' => $card->id,
            'card_identity_snapshot' => json_encode(['name' => $card->name]),
            'category_name_snapshot' => $category->name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $purchase->forceFill(['recurring_card_occurrence_id' => $occurrenceId])->save();
        $goalId = DB::table('financial_goals')->insertGetId([
            'user_id' => $user->id, 'name' => 'Emergency fund', 'target_centavos' => 5000,
            'financial_account_id' => $account->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('financial_goal_activities')->insert([
            'financial_goal_id' => $goalId, 'user_id' => $user->id, 'type' => 'created',
            'occurred_at' => now(), 'business_date' => today()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->postJson('/api/v1/account-data/archive', [])->assertCreated()
            ->assertJsonPath('data.record_count', 20);

        $archiveId = (int) DB::table('financial_data_archives')->where('user_id', $user->id)->value('id');
        $this->assertDatabaseMissing('financial_accounts', ['id' => $account->id]);
        $this->assertDatabaseMissing('credit_card_purchases', ['id' => $purchase->id]);
        $this->assertDatabaseMissing('monthly_budgets', ['id' => $budget->id]);
        $this->assertDatabaseMissing('recurring_card_occurrences', ['id' => $occurrenceId]);
        $this->assertDatabaseMissing('financial_goals', ['id' => $goalId]);
        $this->assertDatabaseHas('financial_accounts', ['id' => $otherAccount->id]);
        $this->assertDatabaseHas('categories', ['id' => $systemCategory->id]);
        $this->getJson('/api/v1/financial-accounts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/account-data/archives')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/account-data/archives/{$archiveId}/records?type=credit_card_purchases")
            ->assertOk()->assertJsonPath('data.0.payload.credit_card_id', $card->id)
            ->assertJsonPath('data.0.payload.category_id', $category->id);
        $this->getJson("/api/v1/account-data/archives/{$archiveId}/records?type=categories")
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function deletion_requires_current_password_and_removes_current_and_past_archives(): void
    {
        $user = User::factory()->create();
        RateLimiter::clear('auth:profile-password:'.$user->id);
        $first = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user)->postJson('/api/v1/account-data/archive', [])->assertCreated();
        $new = FinancialAccount::factory()->create(['user_id' => $user->id]);

        $this->deleteJson('/api/v1/account-data', ['current_password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertDatabaseHas('financial_accounts', ['id' => $new->id]);
        $this->assertDatabaseCount('financial_data_archives', 1);

        $this->deleteJson('/api/v1/account-data', ['current_password' => 'password'])->assertNoContent();
        $this->assertModelExists($user);
        $this->assertDatabaseMissing('financial_accounts', ['id' => $first->id]);
        $this->assertDatabaseMissing('financial_accounts', ['id' => $new->id]);
        $this->assertDatabaseCount('financial_data_archives', 0);
        $this->assertDatabaseCount('financial_data_archive_records', 0);
        $this->getJson('/api/v1/auth/session')->assertOk();
        RateLimiter::clear('auth:profile-password:'.$user->id);
    }

    #[Test]
    public function deletion_limits_wrong_password_attempts_without_erasing_data(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $key = 'auth:profile-password:'.$user->id;
        RateLimiter::clear($key);
        $this->actingAs($user);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->deleteJson('/api/v1/account-data', ['current_password' => 'wrong'])
                ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        }

        $this->deleteJson('/api/v1/account-data', ['current_password' => 'password'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseHas('financial_accounts', ['id' => $account->id]);
        RateLimiter::clear($key);
    }

    #[Test]
    public function archives_are_private_and_requests_reject_unknown_fields(): void
    {
        $owner = User::factory()->create();
        FinancialAccount::factory()->create(['user_id' => $owner->id]);
        $this->actingAs($owner)->postJson('/api/v1/account-data/archive', [])->assertCreated();
        $archiveId = (int) DB::table('financial_data_archives')->where('user_id', $owner->id)->value('id');

        $other = User::factory()->create();
        $this->actingAs($other);
        $this->getJson('/api/v1/account-data/archives')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/account-data/archives/{$archiveId}/records?type=financial_accounts")->assertNotFound();
        $this->postJson('/api/v1/account-data/archive', ['user_id' => $owner->id])->assertUnprocessable();
        $this->deleteJson('/api/v1/account-data', ['current_password' => 'password', 'user_id' => $owner->id])->assertUnprocessable();
        $this->getJson("/api/v1/account-data/archives/{$archiveId}/records?type=users")->assertUnprocessable();

        $this->assertDatabaseHas('financial_data_archives', ['id' => $archiveId]);
    }

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->postJson('/api/v1/account-data/archive', [])->assertUnauthorized();
        $this->deleteJson('/api/v1/account-data', ['current_password' => 'password'])->assertUnauthorized();
        $this->getJson('/api/v1/account-data/archives')->assertUnauthorized();
        $this->getJson('/api/v1/account-data/archives/1/records?type=transactions')->assertUnauthorized();
    }
}
