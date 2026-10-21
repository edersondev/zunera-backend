<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardQueryPerformanceTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function statement_options_and_move_preview_remain_bounded_with_one_thousand_purchases(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17, 'credit_limit_centavos' => 2_000_000]);
        $category = $this->expenseCategory($user);
        $purchase = $this->recordPurchase($card, $category, 1_000, 1, '2026-09-15');
        $statement = $purchase->installments()->firstOrFail()->statement;

        for ($index = 1; $index < 1_000; $index++) {
            $purchaseId = DB::table('credit_card_purchases')->insertGetId([
                'user_id' => $user->id,
                'credit_card_id' => $card->id,
                'category_id' => $category->id,
                'description' => 'Performance seed '.$index,
                'total_amount_centavos' => 1_000,
                'installment_count' => 1,
                'purchase_date' => '2026-09-15',
                'currency_code' => 'BRL',
                'card_name_snapshot' => $card->name,
                'category_name_snapshot' => $category->name,
                'category_status_snapshot' => $category->status->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('credit_card_installments')->insert([
                'user_id' => $user->id,
                'credit_card_purchase_id' => $purchaseId,
                'credit_card_id' => $card->id,
                'credit_card_statement_id' => $statement->id,
                'sequence' => 1,
                'amount_centavos' => 1_000,
                'credit_adjustment_centavos' => 0,
                'recognition_date' => '2026-10-10',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->syncCard($card);
        self::assertSame(1_000_000, $statement->refresh()->original_amount_centavos);

        $optionsUrl = "/api/v1/credit-cards/{$card->id}/statement-options?purchase_date=2026-09-15&limit=12";
        $previewUrl = "/api/v1/credit-card-purchases/{$purchase->id}/move-preview";
        $choice = ['mode' => 'full_schedule', 'target_closing_date' => '2026-11-10'];

        $optionsTimes = $this->measure(110, fn () => $this->getJson($optionsUrl)->assertOk());
        $previewTimes = $this->measure(110, fn () => $this->postJson($previewUrl, $choice)->assertOk());
        self::assertLessThan(2_000, $this->p95(array_slice($optionsTimes, 10)), 'Statement options p95 exceeded 2 seconds.');
        self::assertLessThan(2_000, $this->p95(array_slice($previewTimes, 10)), 'Move preview p95 exceeded 2 seconds.');

        $purchasePlan = DB::select('EXPLAIN QUERY PLAN SELECT * FROM credit_card_purchases WHERE user_id = ? AND id = ?', [$user->id, $purchase->id]);
        $statementPlan = DB::select('EXPLAIN QUERY PLAN SELECT * FROM credit_card_statements WHERE credit_card_id = ? AND closing_date = ?', [$card->id, '2026-10-10']);
        $installmentPlan = DB::select('EXPLAIN QUERY PLAN SELECT * FROM credit_card_installments WHERE credit_card_purchase_id = ? ORDER BY sequence', [$purchase->id]);
        self::assertStringContainsString('SEARCH', $purchasePlan[0]->detail);
        self::assertStringContainsString('SEARCH', $statementPlan[0]->detail);
        self::assertStringContainsString('SEARCH', $installmentPlan[0]->detail);
        self::assertSame(1_000, DB::table('credit_card_purchases')->where('credit_card_id', $card->id)->count());
    }

    /** @return list<float> */
    private function measure(int $count, callable $request): array
    {
        $times = [];
        for ($index = 0; $index < $count; $index++) {
            $start = hrtime(true);
            $request();
            $times[] = (hrtime(true) - $start) / 1_000_000;
        }

        return $times;
    }

    /** @param list<float> $times */
    private function p95(array $times): float
    {
        sort($times);

        return $times[(int) ceil(count($times) * 0.95) - 1];
    }
}
