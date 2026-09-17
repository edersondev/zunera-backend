<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\RecurrencePausedReason;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Services\RecurringTransactions\RecurringDateRange;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class ArchivedAssociationRecurringTransactionsTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function archiving_the_account_pauses_the_rule_and_keeps_history(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();
        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'start_date' => $today,
            'eligibility_starts_on' => $today,
            'schedule_cursor' => $today,
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, $today);

        $this->postJson('/api/v1/financial-accounts/'.$account->id.'/archive')->assertOk();

        $rule->refresh();
        self::assertSame('paused', $rule->state->value);
        self::assertSame(RecurrencePausedReason::AssociationArchived, $rule->paused_reason);
        self::assertSame(1, Transaction::query()->count());

        $detail = $this->getJson('/api/v1/recurring-transactions/'.$rule->id)->assertOk();
        self::assertSame('archived', $detail->json('data.financial_account.status'));
        self::assertNull($detail->json('data.next_expected_occurrence'));

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/resume', [], ['Idempotency-Key' => 'resume-archived'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'recurrence_association_unavailable');
    }

    #[Test]
    public function archiving_the_category_pauses_the_rule(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
        ]);

        $this->postJson('/api/v1/categories/'.$category->id.'/archive')->assertOk();

        $rule->refresh();
        self::assertSame('paused', $rule->state->value);
        self::assertSame(RecurrencePausedReason::AssociationArchived, $rule->paused_reason);
    }

    #[Test]
    public function owner_repairs_the_association_while_paused_and_resumes(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $replacement = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
        ]);
        $this->postJson('/api/v1/financial-accounts/'.$account->id.'/archive')->assertOk();

        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [
            'financial_account_id' => $replacement->id,
        ], ['Idempotency-Key' => 'repair-1'])
            ->assertOk()
            ->assertJsonPath('data.state', 'paused')
            ->assertJsonPath('data.financial_account.id', $replacement->id);

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/resume', [], ['Idempotency-Key' => 'resume-repaired'])
            ->assertOk()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.paused_reason', null);
    }

    #[Test]
    public function restored_association_stays_paused_until_the_owner_resumes(): void
    {
        $user = $this->signInUser();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $category = $this->ownedCategory($user);
        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
        ]);
        $this->postJson('/api/v1/financial-accounts/'.$account->id.'/archive')->assertOk();
        $this->postJson('/api/v1/financial-accounts/'.$account->id.'/restore')->assertOk();

        self::assertSame('paused', $rule->refresh()->state->value);

        $this->postJson('/api/v1/recurring-transactions/'.$rule->id.'/resume', [], ['Idempotency-Key' => 'resume-restored'])
            ->assertOk()
            ->assertJsonPath('data.state', 'active');
    }
}
