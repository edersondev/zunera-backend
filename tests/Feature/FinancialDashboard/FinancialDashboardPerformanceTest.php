<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialDashboard;

use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialDashboard\FinancialDashboardFixtures;
use Tests\TestCase;

final class FinancialDashboardPerformanceTest extends TestCase
{
    use FinancialDashboardFixtures;
    use RefreshDatabase;

    #[Test]
    public function every_projection_stays_within_two_seconds_for_ten_thousand_movements(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user, ['current_balance_centavos' => 900_000]);
        $category = $this->dashboardCategory($user, 'expense');
        $this->seedMovements($user, $account, $category, 10_000);

        $paths = [
            '/api/v1/financial-dashboard/summary',
            '/api/v1/financial-dashboard/accounts',
            '/api/v1/financial-dashboard/expense-distribution',
            '/api/v1/financial-dashboard/evolution',
            '/api/v1/financial-dashboard/recent-activity',
            '/api/v1/financial-dashboard/upcoming-activity',
        ];

        foreach ($paths as $path) {
            $startedAt = microtime(true);
            $this->getJson($path)->assertOk();
            $elapsed = microtime(true) - $startedAt;

            self::assertLessThan(2.0, $elapsed, $path.' took '.$elapsed.' seconds.');
        }
    }

    #[Test]
    public function a_corrected_source_record_moves_every_projection_at_once(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user, ['current_balance_centavos' => 500_000]);
        $category = $this->dashboardCategory($user, 'expense', ['name' => 'Mercado']);
        $transaction = $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'amount_centavos' => 80_000,
            'transaction_date' => '2026-09-06',
        ]);

        $this->getJson('/api/v1/financial-dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.realized_expenses.amount_centavos', 80_000);
        $this->getJson('/api/v1/financial-dashboard/expense-distribution')
            ->assertOk()
            ->assertJsonPath('data.total_expenses.amount_centavos', 80_000);
        $this->getJson('/api/v1/financial-dashboard/evolution?preset=custom&from=2026-09-06&to=2026-09-06')
            ->assertOk()
            ->assertJsonPath('data.intervals.0.expenses.amount_centavos', 80_000);

        $transaction->forceFill(['status' => 'pending', 'removed_at' => now()])->save();

        $this->getJson('/api/v1/financial-dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.realized_expenses.amount_centavos', 0)
            ->assertJsonPath('data.financial_result.amount_centavos', 0);
        $this->getJson('/api/v1/financial-dashboard/expense-distribution')
            ->assertOk()
            ->assertJsonPath('data.total_expenses.amount_centavos', 0)
            ->assertJsonPath('data.categories', []);
        $this->getJson('/api/v1/financial-dashboard/evolution?preset=custom&from=2026-09-06&to=2026-09-06')
            ->assertOk()
            ->assertJsonPath('data.intervals.0.expenses.amount_centavos', 0);
    }

    #[Test]
    public function a_foreign_user_never_enters_another_owners_projections(): void
    {
        $owner = User::factory()->create();
        $account = $this->dashboardAccount($owner, ['current_balance_centavos' => 777_000]);
        $category = $this->dashboardCategory($owner, 'expense', ['name' => 'Privado']);
        $this->dashboardTransaction($owner, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'amount_centavos' => 60_000,
            'transaction_date' => '2026-09-06',
        ]);
        $this->dashboardTransfer($owner, [
            'source_financial_account_id' => $account->id,
            'destination_financial_account_id' => $this->dashboardAccount($owner)->id,
            'amount_centavos' => 10_000,
            'transfer_date' => '2026-09-07',
        ]);

        $this->dashboardSignIn();

        $this->getJson('/api/v1/financial-dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.current_total_balance.amount_centavos', 0)
            ->assertJsonPath('data.realized_expenses.amount_centavos', 0);
        $this->getJson('/api/v1/financial-dashboard/accounts')
            ->assertOk()
            ->assertJsonPath('data.accounts', []);
        $this->getJson('/api/v1/financial-dashboard/recent-activity')
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->getJson('/api/v1/financial-dashboard/expense-distribution')
            ->assertOk()
            ->assertJsonPath('data.categories', []);
    }

    private function seedMovements(User $user, FinancialAccount $account, Category $category, int $count): void
    {
        $now = CarbonImmutable::parse('2026-09-17 10:00:00', 'America/Sao_Paulo');
        $rows = [];

        for ($index = 0; $index < $count; $index++) {
            $rows[] = [
                'user_id' => $user->id,
                'financial_account_id' => $account->id,
                'category_id' => $category->id,
                'type' => $index % 3 === 0 ? 'income' : 'expense',
                'status' => $index % 7 === 0 ? 'pending' : 'effective',
                'description' => 'Movimento '.$index,
                'notes' => null,
                'amount_centavos' => 1_000 + $index,
                'currency_code' => 'BRL',
                'transaction_date' => $now->subDays($index % 400)->toDateString(),
                'search_text' => 'movimento',
                'removed_at' => $index % 11 === 0 ? $now : null,
                'recurring_transaction_id' => null,
                'recurrence_scheduled_date' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('transactions')->insert($chunk);
        }
    }
}
