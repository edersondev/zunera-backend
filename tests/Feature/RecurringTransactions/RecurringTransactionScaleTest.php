<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Models\RecurringTransaction;
use App\Services\RecurringTransactions\RecurringDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class RecurringTransactionScaleTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function first_page_of_five_thousand_rules_stays_within_the_response_budget(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();
        $now = now();
        $rows = [];

        for ($index = 1; $index <= 5_000; $index++) {
            $rows[] = [
                'user_id' => $user->id,
                'financial_account_id' => $account->id,
                'category_id' => $category->id,
                'type' => 'expense',
                'amount_centavos' => 1_000 + $index,
                'currency_code' => 'BRL',
                'description' => 'Regra '.$index,
                'notes' => null,
                'frequency' => 'monthly',
                'start_date' => CarbonImmutable::parse($today)->addDays($index % 28)->toDateString(),
                'end_date' => null,
                'state' => 'active',
                'paused_reason' => null,
                'eligibility_starts_on' => $today,
                'schedule_cursor' => $today,
                'ended_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('recurring_transactions')->insert($chunk);
        }
        self::assertSame(5_000, RecurringTransaction::query()->count());

        $startedAt = microtime(true);
        $response = $this->getJson('/api/v1/recurring-transactions')->assertOk();
        $elapsed = microtime(true) - $startedAt;

        self::assertSame(5_000, $response->json('meta.total'));
        self::assertCount(50, $response->json('data'));
        self::assertLessThan(2.0, $elapsed, 'The first page of 5,000 rules must assemble in under two seconds.');
    }
}
