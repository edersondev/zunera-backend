<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\CreditCards\BillingCycleCalculator;
use Carbon\CarbonImmutable;
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
    public function card_reads_and_credit_card_projections_stay_within_two_seconds_for_ten_thousand_combined_movements(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['credit_limit_centavos' => 10_000_000]);
        $category = $this->expenseCategory($user);
        $account = $this->cardAccount($user);
        $statement = $this->statementFor(
            $card,
            app(BillingCycleCalculator::class)->cycleForDate('2026-09-05', $card->closing_day, $card->due_day),
        );

        $this->seedCombinedMovements($user, $card, $category, $account, $statement, 5_000);

        foreach ([
            '/api/v1/credit-cards',
            '/api/v1/credit-card-statements/'.$statement->id,
            '/api/v1/financial-dashboard/credit-cards',
        ] as $path) {
            $startedAt = microtime(true);
            $this->getJson($path)->assertOk();

            self::assertLessThan(2.0, microtime(true) - $startedAt, $path.' exceeded the two-second response budget.');
        }

        self::assertStringContainsString(
            'credit_card_installments_card_statement_index',
            $this->statementInstallmentPlan($card, $statement),
            'The statement installment aggregate should use the card/statement index.',
        );
    }

    private function seedCombinedMovements(
        User $user,
        CreditCard $card,
        Category $category,
        FinancialAccount $account,
        CreditCardStatement $statement,
        int $count,
    ): void {
        $now = now()->toDateTimeString();
        $transactions = [];
        $purchases = [];

        for ($index = 1; $index <= $count; $index++) {
            $day = str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT);
            $transactions[] = [
                'user_id' => $user->id,
                'financial_account_id' => $account->id,
                'category_id' => $category->id,
                'type' => 'expense',
                'status' => 'effective',
                'description' => 'Cash movement '.$index,
                'notes' => null,
                'amount_centavos' => 100,
                'currency_code' => 'BRL',
                'transaction_date' => '2026-09-'.$day,
                'search_text' => 'cash movement',
                'removed_at' => null,
                'recurring_transaction_id' => null,
                'recurrence_scheduled_date' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $purchases[] = [
                'user_id' => $user->id,
                'credit_card_id' => $card->id,
                'category_id' => $category->id,
                'description' => 'Card movement '.$index,
                'notes' => null,
                'total_amount_centavos' => 100,
                'installment_count' => 1,
                'purchase_date' => '2026-09-'.$day,
                'currency_code' => 'BRL',
                'card_name_snapshot' => $card->name,
                'category_name_snapshot' => $category->name,
                'category_status_snapshot' => $category->status->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($transactions, 500) as $chunk) {
            DB::table('transactions')->insert($chunk);
        }
        foreach (array_chunk($purchases, 500) as $chunk) {
            DB::table('credit_card_purchases')->insert($chunk);
        }

        $statementIds = [$statement->id];
        for ($index = 1; $index < 100; $index++) {
            $closingDate = CarbonImmutable::parse('2018-01-10')->addMonths($index);
            $statementIds[] = CreditCardStatement::query()->create([
                'user_id' => $user->id,
                'credit_card_id' => $card->id,
                'period_from' => $closingDate->subMonth()->addDay(),
                'period_to' => $closingDate,
                'closing_date' => $closingDate,
                'due_date' => $closingDate->addDays(7),
                'status' => 'paid',
            ])->id;
        }

        $installments = DB::table('credit_card_purchases')
            ->where('user_id', $user->id)
            ->where('credit_card_id', $card->id)
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->map(fn (int $purchaseId, int $index): array => [
                'user_id' => $user->id,
                'credit_card_purchase_id' => $purchaseId,
                'credit_card_id' => $card->id,
                'credit_card_statement_id' => $statementIds[$index % count($statementIds)],
                'sequence' => 1,
                'amount_centavos' => 100,
                'credit_adjustment_centavos' => 0,
                'recognition_date' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($installments, 500) as $chunk) {
            DB::table('credit_card_installments')->insert($chunk);
        }
    }

    private function statementInstallmentPlan(CreditCard $card, CreditCardStatement $statement): string
    {
        return json_encode(DB::select(
            'EXPLAIN QUERY PLAN SELECT SUM(amount_centavos) FROM credit_card_installments WHERE credit_card_id = ? AND credit_card_statement_id = ?',
            [$card->id, $statement->id],
        ), JSON_THROW_ON_ERROR);
    }
}
