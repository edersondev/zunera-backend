<?php

declare(strict_types=1);

namespace Tests\Unit\FinancialDashboard;

use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionStatus;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Services\FinancialDashboard\DashboardUpcomingActivityService;
use App\Services\RecurringTransactions\RecurringScheduleCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialDashboard\FinancialDashboardFixtures;
use Tests\TestCase;

final class DashboardUpcomingActivityServiceTest extends TestCase
{
    use FinancialDashboardFixtures;
    use RefreshDatabase;

    #[Test]
    public function its_horizon_includes_business_date_and_lasts_thirty_days(): void
    {
        $user = User::factory()->create();

        $projection = $this->service()->upcoming($user, '2026-09-17');

        self::assertSame('2026-09-17', $projection['from']);
        self::assertSame('2026-10-16', $projection['to']);
        self::assertSame([], $projection['items']);
    }

    #[Test]
    public function the_schedule_calculator_projects_only_active_rules_inside_the_window(): void
    {
        $rule = $this->weeklyRule(RecurrenceState::Active, '2026-09-19');
        $calculator = app(RecurringScheduleCalculator::class);

        self::assertSame(
            ['2026-09-19', '2026-09-26'],
            $calculator->futureDatesBetween($rule, '2026-09-18', '2026-09-30'),
        );

        $paused = $this->weeklyRule(RecurrenceState::Paused, '2026-09-19');
        self::assertSame([], $calculator->futureDatesBetween($paused, '2026-09-18', '2026-09-30'));
        self::assertSame(
            ['2026-09-19', '2026-09-26'],
            $calculator->datesBetween($paused, '2026-09-18', '2026-09-30'),
            'Existing calendar math stays unchanged for other callers.',
        );
    }

    #[Test]
    public function a_generated_transaction_takes_precedence_over_its_rule_projection(): void
    {
        $user = User::factory()->create();
        $rule = $this->weeklyRule(RecurrenceState::Active, '2026-09-19', $user);

        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $rule->financial_account_id,
            'category_id' => $rule->category_id,
            'amount_centavos' => $rule->amount_centavos,
            'transaction_date' => '2026-09-26',
            'status' => TransactionStatus::Pending,
            'recurring_transaction_id' => $rule->id,
            'recurrence_scheduled_date' => '2026-09-26',
        ]);

        $projection = $this->service()->upcoming($user, '2026-09-17');
        $dates = array_map(fn (array $item): string => $item['expected_date'], $projection['items']);

        // The generated occurrence replaces its rule date, and later eligible
        // dates of the weekly rule keep projecting inside the horizon.
        self::assertSame(
            ['2026-09-19', '2026-09-26', '2026-10-03', '2026-10-10'],
            $dates,
        );
        self::assertSame(
            [
                'recurring_occurrence',
                'pending_transaction',
                'recurring_occurrence',
                'recurring_occurrence',
            ],
            array_map(fn (array $item): string => $item['source_kind'], $projection['items']),
        );
    }

    private function service(): DashboardUpcomingActivityService
    {
        return app(DashboardUpcomingActivityService::class);
    }

    private function weeklyRule(RecurrenceState $state, string $startDate, ?User $user = null): RecurringTransaction
    {
        $user ??= User::factory()->create();

        return RecurringTransaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $this->dashboardAccount($user)->id,
            'category_id' => $this->dashboardCategory($user)->id,
            'type' => 'expense',
            'amount_centavos' => 8_000,
            'description' => 'Academia',
            'frequency' => RecurrenceFrequency::Weekly,
            'start_date' => $startDate,
            'eligibility_starts_on' => $startDate,
            'schedule_cursor' => $startDate,
            'state' => $state,
        ]);
    }
}
