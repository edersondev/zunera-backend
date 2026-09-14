<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Models\Transaction;
use App\Services\RecurringTransactions\RecurringDateRange;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\RecurringTransactions\RecurringTransactionFeatureTestCase;

final class RecurringTransactionSourceTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function ordinary_transactions_report_a_null_recurrence_source(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);

        $transaction = Transaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
        ]);

        $this->getJson('/api/v1/transactions/'.$transaction->id)
            ->assertOk()
            ->assertJsonPath('data.recurrence_source', null);

        $listed = $this->getJson('/api/v1/transactions')->assertOk();
        self::assertNull($listed->json('data.0.recurrence_source'));
    }

    #[Test]
    public function generated_occurrences_report_their_rule_and_scheduled_date(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();
        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => $today,
            'eligibility_starts_on' => $today,
            'schedule_cursor' => $today,
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, $today);

        $occurrence = Transaction::query()->sole();

        $this->getJson('/api/v1/transactions/'.$occurrence->id)
            ->assertOk()
            ->assertJsonPath('data.recurrence_source.id', $rule->id)
            ->assertJsonPath('data.recurrence_source.scheduled_date', $today);

        $listed = $this->getJson('/api/v1/transactions')->assertOk();
        self::assertSame($rule->id, $listed->json('data.0.recurrence_source.id'));
        self::assertSame($today, $listed->json('data.0.recurrence_source.scheduled_date'));
    }
}
