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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ArchiveRestoreTest extends TestCase
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
    public function restore_recovers_linked_financial_records_and_saves_current_workspace(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherAccount = FinancialAccount::factory()->create(['user_id' => $other->id]);
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $destination = FinancialAccount::factory()->archived()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $system = Category::factory()->system()->create();
        Transaction::factory()->create(['user_id' => $user->id, 'financial_account_id' => $account->id, 'category_id' => $system->id]);
        Transfer::factory()->create(['user_id' => $user->id, 'source_financial_account_id' => $account->id, 'destination_financial_account_id' => $destination->id]);
        RecurringTransaction::factory()->create(['user_id' => $user->id, 'financial_account_id' => $account->id, 'category_id' => $category->id]);
        $budget = MonthlyBudget::factory()->create(['user_id' => $user->id]);
        BudgetCategoryPlan::factory()->create(['monthly_budget_id' => $budget->id, 'category_id' => $category->id]);
        $card = CreditCard::factory()->create(['user_id' => $user->id]);
        $purchase = CreditCardPurchase::factory()->forCard($card)->withCategory($category)->create();
        $statement = CreditCardStatement::factory()->forCard($card)->create();
        $installment = CreditCardInstallment::factory()->forPurchase($purchase, $statement)->create();
        CreditCardStatementPayment::factory()->create(['user_id' => $user->id, 'credit_card_statement_id' => $statement->id, 'credit_card_id' => $card->id, 'financial_account_id' => $account->id]);
        $credit = CreditCardCreditEvent::factory()->forPurchase($purchase, 500)->create();
        CreditCardCreditApplication::factory()->create(['user_id' => $user->id, 'credit_card_credit_event_id' => $credit->id, 'credit_card_id' => $card->id, 'credit_card_installment_id' => $installment->id, 'credit_card_statement_id' => $statement->id]);
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
            'financial_goal_id' => $goalId, 'user_id' => $user->id, 'type' => 'account_changed',
            'financial_account_id_at_time' => $account->id,
            'details' => json_encode([
                'before' => ['financial_account_id' => $account->id],
                'changes' => ['financial_account_id' => $destination->id],
            ]),
            'occurred_at' => now(), 'business_date' => today()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user);
        $archiveId = (int) $this->postJson('/api/v1/account-data/archive', [])->assertCreated()->json('data.id');
        $current = FinancialAccount::factory()->create(['user_id' => $user->id, 'name' => 'Current account']);

        $response = $this->postJson("/api/v1/account-data/archives/{$archiveId}/restore", [])->assertOk()
            ->assertJsonPath('data.restored_archive_id', $archiveId)
            ->assertJsonPath('data.restored_record_count', 20);
        $previousId = (int) $response->json('data.previous_archive_id');
        $this->assertNotSame($archiveId, $previousId);
        $this->assertDatabaseHas('financial_data_archives', ['id' => $archiveId]);
        $this->assertDatabaseHas('financial_data_archives', ['id' => $previousId, 'record_count' => 2]);
        $this->assertDatabaseMissing('financial_accounts', ['id' => $current->id]);
        $this->assertDatabaseHas('financial_accounts', ['id' => $otherAccount->id]);

        $restoredAccount = DB::table('financial_accounts')->where('user_id', $user->id)->where('name', $account->name)->first();
        $restoredCategory = DB::table('categories')->where('user_id', $user->id)->where('name', $category->name)->first();
        $restoredCard = DB::table('credit_cards')->where('user_id', $user->id)->first();
        $restoredTransaction = DB::table('transactions')->where('user_id', $user->id)->first();
        $restoredOccurrence = DB::table('recurring_card_occurrences')->where('user_id', $user->id)->first();
        $restoredPurchase = DB::table('credit_card_purchases')->where('user_id', $user->id)->first();
        $restoredGoal = DB::table('financial_goals')->where('user_id', $user->id)->first();
        $this->assertNotEquals($account->id, $restoredAccount->id);
        $this->assertSame($account->current_balance_centavos, $restoredAccount->current_balance_centavos);
        $this->assertSame('archived', DB::table('financial_accounts')->where('user_id', $user->id)->where('name', $destination->name)->value('status'));
        $this->assertEquals($system->id, $restoredTransaction->category_id);
        $this->assertEquals($restoredAccount->id, $restoredTransaction->financial_account_id);
        $this->assertEquals($restoredCategory->id, $restoredOccurrence->category_id_original);
        $this->assertEquals($restoredCard->id, $restoredOccurrence->credit_card_id_original);
        $this->assertEquals($restoredOccurrence->id, $restoredPurchase->recurring_card_occurrence_id);
        $this->assertEquals($restoredAccount->id, $restoredGoal->financial_account_id);
        $this->assertEquals($restoredAccount->id, DB::table('financial_goal_activities')->where('user_id', $user->id)->value('financial_account_id_at_time'));
        $activityDetails = json_decode(DB::table('financial_goal_activities')->where('user_id', $user->id)->value('details'), true);
        $restoredDestination = DB::table('financial_accounts')->where('user_id', $user->id)->where('name', $destination->name)->first();
        $this->assertEquals($restoredAccount->id, $activityDetails['before']['financial_account_id']);
        $this->assertEquals($restoredDestination->id, $activityDetails['changes']['financial_account_id']);
        $this->assertDatabaseCount('budget_category_plans', 1);
        $this->assertDatabaseCount('credit_card_credit_applications', 1);
        $this->assertDatabaseHas('notification_projection_facts', ['user_id' => $user->id, 'source_kind' => 'credit_card_statement']);
        $this->getJson('/api/v1/auth/session')->assertOk();
    }

    #[Test]
    public function restore_to_empty_workspace_keeps_archive_without_creating_a_backup(): void
    {
        $user = User::factory()->create();
        FinancialAccount::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);
        $archiveId = (int) $this->postJson('/api/v1/account-data/archive', [])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/account-data/archives/{$archiveId}/restore", [])
            ->assertOk()->assertJsonPath('data.previous_archive_id', null);
        $this->assertDatabaseCount('financial_data_archives', 1);
        $this->assertDatabaseCount('financial_accounts', 1);

        $response = $this->postJson("/api/v1/account-data/archives/{$archiveId}/restore", [])->assertOk();
        $this->assertNotNull($response->json('data.previous_archive_id'));
        $this->assertDatabaseCount('financial_data_archives', 2);
        $this->assertDatabaseCount('financial_accounts', 1);
    }

    #[Test]
    public function empty_archive_restores_an_empty_workspace_and_saves_current_data(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $archiveId = (int) $this->postJson('/api/v1/account-data/archive', [])->assertCreated()->json('data.id');
        FinancialAccount::factory()->create(['user_id' => $user->id]);

        $response = $this->postJson("/api/v1/account-data/archives/{$archiveId}/restore", [])
            ->assertOk()->assertJsonPath('data.restored_record_count', 0);
        $this->assertNotNull($response->json('data.previous_archive_id'));
        $this->assertDatabaseCount('financial_accounts', 0);
        $this->assertDatabaseCount('financial_data_archives', 2);
    }

    #[Test]
    public function restore_requires_ownership_and_an_empty_request(): void
    {
        $owner = User::factory()->create();
        FinancialAccount::factory()->create(['user_id' => $owner->id]);
        $this->actingAs($owner);
        $archiveId = (int) $this->postJson('/api/v1/account-data/archive', [])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/account-data/archives/{$archiveId}/restore", ['user_id' => $owner->id])
            ->assertUnprocessable();
        $other = User::factory()->create();
        $this->actingAs($other)->postJson("/api/v1/account-data/archives/{$archiveId}/restore", [])
            ->assertNotFound();
        $this->assertDatabaseCount('financial_accounts', 0);
        $this->assertDatabaseCount('financial_data_archives', 1);
    }

    #[Test]
    public function malformed_snapshot_rolls_back_without_creating_a_backup(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);
        $archiveId = (int) $this->postJson('/api/v1/account-data/archive', [])->assertCreated()->json('data.id');
        $current = FinancialAccount::factory()->create(['user_id' => $user->id]);
        DB::table('financial_data_archive_records')->where('financial_data_archive_id', $archiveId)
            ->where('record_type', 'financial_accounts')->update(['payload' => json_encode(['id' => $account->id, 'user_id' => 999])]);

        $this->postJson("/api/v1/account-data/archives/{$archiveId}/restore", [])
            ->assertConflict()->assertJsonPath('code', 'archive_restore_unavailable');
        $this->assertDatabaseHas('financial_accounts', ['id' => $current->id]);
        $this->assertDatabaseCount('financial_data_archives', 1);
    }

    #[Test]
    public function snapshot_missing_a_defaulted_column_cannot_replace_current_data(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $goalId = DB::table('financial_goals')->insertGetId([
            'user_id' => $user->id, 'name' => 'Paused goal', 'target_centavos' => 5000,
            'financial_account_id' => $account->id, 'status' => 'paused',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($user);
        $archiveId = (int) $this->postJson('/api/v1/account-data/archive', [])->assertCreated()->json('data.id');
        $current = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $record = DB::table('financial_data_archive_records')->where('financial_data_archive_id', $archiveId)
            ->where('record_type', 'financial_goals')->where('source_id', $goalId)->first();
        $payload = json_decode($record->payload, true);
        unset($payload['status']);
        DB::table('financial_data_archive_records')->where('id', $record->id)
            ->update(['payload' => json_encode($payload)]);

        $this->postJson("/api/v1/account-data/archives/{$archiveId}/restore", [])
            ->assertConflict()->assertJsonPath('code', 'archive_restore_unavailable');
        $this->assertDatabaseHas('financial_accounts', ['id' => $current->id]);
        $this->assertDatabaseCount('financial_data_archives', 1);
    }

    #[Test]
    public function missing_system_category_aborts_restore_without_changing_live_data(): void
    {
        $user = User::factory()->create();
        $system = Category::factory()->system()->create();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        Transaction::factory()->create(['user_id' => $user->id, 'financial_account_id' => $account->id, 'category_id' => $system->id]);
        $this->actingAs($user);
        $archiveId = (int) $this->postJson('/api/v1/account-data/archive', [])->assertCreated()->json('data.id');
        $current = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $system->delete();

        $this->postJson("/api/v1/account-data/archives/{$archiveId}/restore", [])
            ->assertConflict()->assertJsonPath('code', 'archive_restore_unavailable');
        $this->assertDatabaseHas('financial_accounts', ['id' => $current->id]);
        $this->assertDatabaseCount('financial_data_archives', 1);
    }

    #[Test]
    public function restore_remaps_ids_reused_by_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        Transaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
        ]);
        $this->actingAs($user);
        $archiveId = (int) $this->postJson('/api/v1/account-data/archive', [])->assertCreated()->json('data.id');
        FinancialAccount::factory()->create(['id' => $account->id, 'user_id' => $other->id]);
        Category::factory()->create(['id' => $category->id, 'user_id' => $other->id]);

        $this->postJson("/api/v1/account-data/archives/{$archiveId}/restore", [])->assertOk();
        $restoredAccount = DB::table('financial_accounts')->where('user_id', $user->id)->first();
        $restoredCategory = DB::table('categories')->where('user_id', $user->id)->first();
        $transaction = DB::table('transactions')->where('user_id', $user->id)->first();
        $this->assertNotEquals($account->id, $restoredAccount->id);
        $this->assertNotEquals($category->id, $restoredCategory->id);
        $this->assertEquals($restoredAccount->id, $transaction->financial_account_id);
        $this->assertEquals($restoredCategory->id, $transaction->category_id);
        $this->assertDatabaseHas('financial_accounts', ['id' => $account->id, 'user_id' => $other->id]);
    }

    #[Test]
    public function restore_matches_a_system_category_by_identity_after_its_id_changes(): void
    {
        $user = User::factory()->create();
        $system = Category::factory()->system()->create();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        Transaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $system->id,
        ]);
        $this->actingAs($user);
        $archiveId = (int) $this->postJson('/api/v1/account-data/archive', [])->assertCreated()->json('data.id');
        $system->delete();
        $replacement = Category::factory()->system()->create([
            'name' => $system->name,
            'normalized_name' => $system->normalized_name,
            'classification' => $system->classification,
        ]);

        $this->postJson("/api/v1/account-data/archives/{$archiveId}/restore", [])->assertOk();
        $this->assertNotEquals($system->id, $replacement->id);
        $this->assertEquals($replacement->id, DB::table('transactions')->where('user_id', $user->id)->value('category_id'));
        $this->assertDatabaseCount('categories', 1);
    }

    #[Test]
    public function guest_cannot_restore_an_archive(): void
    {
        $this->postJson('/api/v1/account-data/archives/1/restore', [])->assertUnauthorized();
    }
}
