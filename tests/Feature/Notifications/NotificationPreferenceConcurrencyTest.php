<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\Transactions\TransactionStatus;
use App\Models\BudgetCategoryPlan;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\FinancialAccount;
use App\Models\FinancialGoal;
use App\Models\FinancialGoalActivity;
use App\Models\MonthlyBudget;
use App\Models\NotificationEvent;
use App\Models\NotificationPreferenceChange;
use App\Models\NotificationProjectionFact;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notifications\NotificationFactDispatcher;
use App\Services\Notifications\NotificationReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class NotificationPreferenceConcurrencyTest extends TestCase
{
    #[Test]
    public function overlapping_mysql_toggle_and_source_qualification_follow_serialized_owner_order(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('This verifies real MySQL row-lock ordering.');
        }
        Artisan::call('migrate', ['--force' => true]);

        foreach (['credit_cards', 'recurring_transactions', 'budgets', 'financial_goals'] as $category) {
            $user = User::factory()->create();
            try {
                [$kind, $sourceId] = $this->source($user, $category);
                $this->race((int) $user->id, $category, $kind, $sourceId);

                $fact = NotificationProjectionFact::query()->where('user_id', $user->id)->where('source_kind', $kind)->firstOrFail();
                $change = NotificationPreferenceChange::query()->where('user_id', $user->id)->where('category', $category)->firstOrFail();
                self::assertNotNull($fact->qualified_at);
                self::assertNotSame($fact->qualified_at->format('Y-m-d H:i:s.u'), $change->effective_at->format('Y-m-d H:i:s.u'));

                $result = app(NotificationReconciler::class)->drain(app(NotificationFactDispatcher::class)->handle(...), userId: (int) $user->id);
                self::assertSame(0, $result['failed']);
                $event = NotificationEvent::query()->where('user_id', $user->id)->where('source_kind', $kind)->firstOrFail();
                $expected = $fact->qualified_at->lessThan($change->effective_at) ? 'visible' : 'suppressed';
                self::assertSame($expected, $event->visibility, $category.' must use the first-qualified owner order.');
            } finally {
                $this->cleanup($user);
            }
        }
    }

    /** @return array{string, int} */
    private function source(User $user, string $category): array
    {
        $today = CarbonImmutable::now('America/Sao_Paulo')->startOfDay();
        if ($category === 'credit_cards') {
            $card = CreditCard::factory()->withUser($user)->create();
            $statement = CreditCardStatement::factory()->forCard($card)->closed()
                ->closing($today->subDays(5)->toDateString(), $today->addDay()->toDateString())
                ->create(['original_amount_centavos' => 10000]);

            return ['credit_card_statement', (int) $statement->id];
        }
        if ($category === 'recurring_transactions') {
            $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
            $expense = Category::factory()->create(['user_id' => $user->id]);
            $rule = RecurringTransaction::factory()->create([
                'user_id' => $user->id, 'financial_account_id' => $account->id,
                'category_id' => $expense->id, 'start_date' => $today->toDateString(),
                'eligibility_starts_on' => $today->toDateString(), 'schedule_cursor' => $today->toDateString(),
            ]);
            $transaction = Transaction::factory()->create([
                'user_id' => $user->id, 'financial_account_id' => $account->id, 'category_id' => $expense->id,
                'status' => TransactionStatus::Pending, 'transaction_date' => $today->toDateString(),
                'recurring_transaction_id' => $rule->id, 'recurrence_scheduled_date' => $today->toDateString(),
            ]);

            return ['transaction', (int) $transaction->id];
        }
        if ($category === 'budgets') {
            $expense = Category::factory()->create(['user_id' => $user->id]);
            $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
            $month = MonthlyBudget::factory()->create(['user_id' => $user->id, 'budget_year' => $today->year, 'budget_month' => $today->month]);
            $plan = BudgetCategoryPlan::factory()->create(['monthly_budget_id' => $month->id, 'category_id' => $expense->id, 'planned_amount_centavos' => 10000]);
            Transaction::factory()->create([
                'user_id' => $user->id, 'financial_account_id' => $account->id,
                'category_id' => $expense->id, 'amount_centavos' => 9000,
                'transaction_date' => $today->toDateString(),
            ]);

            return ['budget_plan', (int) $plan->id];
        }

        $goal = FinancialGoal::query()->create(['user_id' => $user->id, 'name' => 'Race goal', 'target_centavos' => 100, 'status' => 'active']);
        FinancialGoalActivity::query()->create([
            'financial_goal_id' => $goal->id, 'user_id' => $user->id, 'type' => 'allocated',
            'amount_centavos' => 100, 'occurred_at' => now(), 'business_date' => $today->toDateString(),
        ]);

        return ['goal', (int) $goal->id];
    }

    private function race(int $userId, string $category, string $kind, int $sourceId): void
    {
        $barrier = tempnam(sys_get_temp_dir(), 'notif-race-');
        self::assertIsString($barrier);
        unlink($barrier);
        $ready = [$barrier.'-toggle', $barrier.'-fact'];
        $worker = base_path('tests/Support/notification_preference_worker.php');
        $processes = [];
        try {
            foreach (['toggle', 'fact'] as $index => $action) {
                $process = new Process([PHP_BINARY, $worker, $barrier, $ready[$index], $action,
                    (string) $userId, $category, $kind, (string) $sourceId]);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (! file_exists($ready[0]) || ! file_exists($ready[1])) {
                if (microtime(true) >= $deadline) {
                    self::fail('Concurrent workers did not reach the barrier.');
                }
                usleep(1000);
            }
            touch($barrier);
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ([$barrier, ...$ready] as $path) {
                @unlink($path);
            }
        }
    }

    private function cleanup(User $user): void
    {
        Transaction::query()->where('user_id', $user->id)->delete();
        RecurringTransaction::query()->where('user_id', $user->id)->delete();
        BudgetCategoryPlan::query()->whereHas('monthlyBudget', fn ($query) => $query->where('user_id', $user->id))->delete();
        MonthlyBudget::query()->where('user_id', $user->id)->delete();
        FinancialGoalActivity::query()->where('user_id', $user->id)->delete();
        FinancialGoal::query()->where('user_id', $user->id)->delete();
        CreditCardStatement::query()->where('user_id', $user->id)->delete();
        CreditCard::query()->where('user_id', $user->id)->delete();
        FinancialAccount::query()->where('user_id', $user->id)->delete();
        Category::query()->where('user_id', $user->id)->delete();
        $user->delete();
    }
}
