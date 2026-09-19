<?php

declare(strict_types=1);

namespace Tests\Feature\Budgets;

use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\MonthlyBudget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Budgets\BudgetFixtures;
use Tests\TestCase;

/**
 * Selected-month reads must stay usable at the documented scale. This lives in
 * the registered Feature suite because `phpunit.xml` declares no Performance
 * suite, so a file under `tests/Performance` would never execute.
 */
final class BudgetPerformanceTest extends TestCase
{
    use BudgetFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_selected_month_read_stays_within_two_seconds_for_ten_thousand_movements(): void
    {
        $user = $this->budgetSignIn();
        $account = $this->budgetAccount($user, ['current_balance_centavos' => 900_000]);
        $food = $this->budgetCategory($user, 'expense');
        $transport = $this->budgetCategory($user, 'expense');
        $budgetId = $this->postJson('/api/v1/budgets', ['year' => 2026, 'month' => 9])->json('data.budget.id');
        $this->postJson("/api/v1/budgets/{$budgetId}/plans", ['category_id' => $food->id, 'planned_amount_centavos' => 500_000])->assertCreated();
        $this->seedMovements($user, $account, $food, $transport, 10_000);

        $startedAt = microtime(true);
        $this->getJson($this->budgetPath(2026, 9))->assertOk();
        $elapsed = microtime(true) - $startedAt;

        self::assertLessThan(2.0, $elapsed, 'Budget month read took '.$elapsed.' seconds.');
        self::assertStringContainsString('INDEX', $this->aggregateQueryPlan(), 'The budget aggregate should use an index rather than a full scan.');
    }

    private function seedMovements(User $user, FinancialAccount $account, Category $food, Category $transport, int $total): void
    {
        $now = now()->toDateTimeString();
        $rows = [];

        for ($index = 1; $index <= $total; $index++) {
            $day = str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT);
            $rows[] = [
                'user_id' => $user->id,
                'financial_account_id' => $account->id,
                'category_id' => $index % 3 === 0 ? $transport->id : $food->id,
                'type' => 'expense',
                'status' => $index % 5 === 0 ? 'pending' : 'effective',
                'description' => 'Movement '.$index,
                'notes' => null,
                'amount_centavos' => 1_000 + ($index % 500),
                'currency_code' => 'BRL',
                'transaction_date' => '2026-09-'.$day,
                'search_text' => 'movement '.$index,
                'removed_at' => $index % 97 === 0 ? $now : null,
                'recurring_transaction_id' => null,
                'recurrence_scheduled_date' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 1_000) {
                Transaction::query()->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            Transaction::query()->insert($rows);
        }

        self::assertSame($total, Transaction::query()->where('user_id', $user->id)->count());
        self::assertTrue(MonthlyBudget::query()->whereKey(1)->exists());
    }

    private function aggregateQueryPlan(): string
    {
        $plan = DB::select('EXPLAIN QUERY PLAN SELECT category_id, SUM(amount_centavos) as total_centavos FROM transactions WHERE user_id = 1 AND type = ? AND status = ? AND removed_at IS NULL AND date(transaction_date) >= ? AND date(transaction_date) <= ? GROUP BY category_id', ['expense', 'effective', '2026-09-01', '2026-09-30']);

        return json_encode($plan, JSON_THROW_ON_ERROR);
    }
}
