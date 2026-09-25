<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Models\RecurringTransaction;
use App\Services\RecurringTransactions\RecurringDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;

final class RecurringTransactionScaleTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    #[RunInSeparateProcess]
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

    #[Test]
    public function card_rule_list_and_occurrence_queries_stay_batched_without_per_rule_lookups(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $today = RecurringDateRange::businessDate();
        $now = now();

        $rows = [];
        for ($index = 1; $index <= 1_000; $index++) {
            $rows[] = [
                'user_id' => $user->id,
                'destination_type' => 'credit_card',
                'financial_account_id' => null,
                'credit_card_id' => $card->id,
                'generation_mode' => 'automatic',
                'category_id' => $category->id,
                'type' => 'expense',
                'amount_centavos' => 1_000 + $index,
                'currency_code' => 'BRL',
                'description' => 'Regra cartão '.$index,
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

        $ruleIds = RecurringTransaction::query()->where('credit_card_id', $card->id)->pluck('id')->all();
        $occurrences = [];
        foreach ($ruleIds as $ruleId) {
            $occurrences[] = [
                'user_id' => $user->id,
                'recurring_transaction_id' => $ruleId,
                'scheduled_date' => $today,
                'generation_mode_snapshot' => 'automatic',
                'scheduled_amount_centavos' => 1_000,
                'description_snapshot' => 'Ocorrência',
                'notes_snapshot' => null,
                'category_id_original' => $category->id,
                'credit_card_id_original' => $card->id,
                'card_identity_snapshot' => json_encode(['name' => $card->name, 'institution_name' => $card->institution_name, 'last_four' => $card->last_four, 'status' => 'active']),
                'category_name_snapshot' => $category->name,
                'state' => 'recorded',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($occurrences, 500) as $chunk) {
            DB::table('recurring_card_occurrences')->insert($chunk);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/recurring-transactions?destination_type=credit_card&per_page=50')->assertOk();
        $listQueries = count(DB::getQueryLog());

        self::assertLessThan(12, $listQueries, 'The card rule list must avoid per-rule occurrence or card lookups.');

        DB::flushQueryLog();
        $ruleId = $ruleIds[0];
        $this->getJson('/api/v1/recurring-transactions/'.$ruleId.'/occurrences?per_page=10')->assertOk();
        self::assertLessThan(25, count(DB::getQueryLog()), 'Occurrence listing must stay batched.');
    }
}
