<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialHistory;

use App\Models\Transaction;
use App\Services\RecurringTransactions\RecurringDateRange;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\RecurringTransactions\RecurringTransactionFeatureTestCase;

final class RecurringTransactionHistoryTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function mixed_history_exposes_recurrence_origin_for_generated_entries_only(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();

        $ordinary = Transaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'transaction_date' => $today,
        ]);
        $rule = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'start_date' => $today,
            'eligibility_starts_on' => $today,
            'schedule_cursor' => $today,
        ]);
        app(RecurringOccurrenceService::class)->processRule($rule->id, $today);
        $occurrence = Transaction::query()->where('recurring_transaction_id', $rule->id)->sole();

        $entries = collect($this->getJson('/api/v1/financial-history')->assertOk()->json('data'))
            ->keyBy('id');

        self::assertNull($entries[$ordinary->id]['recurrence_source']);
        self::assertSame($rule->id, $entries[$occurrence->id]['recurrence_source']['id']);
        self::assertSame($today, $entries[$occurrence->id]['recurrence_source']['scheduled_date']);
    }
}
