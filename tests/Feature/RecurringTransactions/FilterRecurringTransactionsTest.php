<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionType;
use App\Models\User;
use App\Services\RecurringTransactions\RecurringDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class FilterRecurringTransactionsTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function combined_filters_use_and_semantics_inside_the_owner_scope(): void
    {
        $user = $this->signInUser();
        $stranger = User::factory()->create();
        $account = $this->ownedAccount($user);
        $incomeCategory = $this->ownedCategory($user, TransactionType::Income);
        $expenseCategory = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();

        $matching = $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $incomeCategory->id,
            'type' => TransactionType::Income,
            'frequency' => RecurrenceFrequency::Yearly,
            'state' => RecurrenceState::Active,
            'start_date' => CarbonImmutable::parse($today)->addDays(10)->toDateString(),
        ]);
        $this->rule($user, [
            'financial_account_id' => $account->id,
            'category_id' => $expenseCategory->id,
            'type' => TransactionType::Expense,
            'frequency' => RecurrenceFrequency::Yearly,
        ]);
        $this->rule($stranger, []);

        $response = $this->getJson('/api/v1/recurring-transactions?type=income&frequency=yearly&state=active&financial_account_id='.$account->id)
            ->assertOk();
        self::assertSame(1, $response->json('meta.total'));
        self::assertSame($matching->id, $response->json('data.0.id'));
        self::assertSame(1, $response->json('meta.current_page'));
        self::assertSame(50, $response->json('meta.per_page'));
        self::assertNull($response->json('links.next'));

        $noMatch = $this->getJson('/api/v1/recurring-transactions?type=income&state=ended')->assertOk();
        self::assertSame(0, $noMatch->json('meta.total'));
        self::assertSame([], $noMatch->json('data'));

        $all = $this->getJson('/api/v1/recurring-transactions')->assertOk();
        self::assertSame(2, $all->json('meta.total'));
    }

    #[Test]
    public function pagination_is_stable_and_respects_the_page_size_limit(): void
    {
        $user = $this->signInUser();
        for ($index = 0; $index < 5; $index++) {
            $this->rule($user, []);
        }

        $page = $this->getJson('/api/v1/recurring-transactions?page=2&per_page=2')->assertOk();
        self::assertSame(5, $page->json('meta.total'));
        self::assertSame(2, $page->json('meta.current_page'));
        self::assertSame(3, $page->json('meta.last_page'));
        self::assertCount(2, $page->json('data'));
        self::assertSame(2, $page->json('meta.per_page'));

        $this->getJson('/api/v1/recurring-transactions?per_page=0')->assertUnprocessable();
    }
}
