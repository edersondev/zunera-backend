<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialDashboard;

use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionStatus;
use App\Models\RecurringTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialDashboard\FinancialDashboardFixtures;
use Tests\TestCase;

final class DashboardUpcomingActivityTest extends TestCase
{
    use FinancialDashboardFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_horizon_includes_today_and_the_following_twenty_nine_calendar_days(): void
    {
        $this->dashboardSignIn();

        $this->getJson('/api/v1/financial-dashboard/upcoming-activity')
            ->assertOk()
            ->assertJsonPath('meta.from', '2026-09-17')
            ->assertJsonPath('meta.to', '2026-10-16')
            ->assertJsonPath('data', []);
    }

    #[Test]
    public function current_and_future_pending_transactions_are_expected(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user, ['name' => 'Conta corrente']);
        $category = $this->dashboardCategory($user, 'expense', ['name' => 'Contas']);

        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'amount_centavos' => 21_500,
            'description' => 'Condomínio',
            'transaction_date' => '2026-09-25',
            'status' => TransactionStatus::Pending,
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'amount_centavos' => 11_000,
            'transaction_date' => '2026-09-17',
            'status' => TransactionStatus::Pending,
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'amount_centavos' => 5_000,
            'transaction_date' => '2026-10-18',
            'status' => TransactionStatus::Pending,
        ]);
        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 9_000,
            'transaction_date' => '2026-09-20',
            'status' => TransactionStatus::Effective,
        ]);

        $response = $this->getJson('/api/v1/financial-dashboard/upcoming-activity')->assertOk();
        $data = $response->json('data');

        self::assertCount(2, $data);
        self::assertSame('pending_transaction', $data[0]['source_kind']);
        $response->assertJsonPath('data.0.expected_date', '2026-09-17')
            ->assertJsonPath('data.0.type', 'expense')
            ->assertJsonPath('data.0.state', 'expected')
            ->assertJsonPath('data.0.amount.amount_centavos', 11_000)
            ->assertJsonPath('data.0.account.name', 'Conta corrente')
            ->assertJsonPath('data.0.category.name', 'Contas')
            ->assertJsonPath('data.1.expected_date', '2026-09-25')
            ->assertJsonPath('data.1.description', 'Condomínio');
    }

    #[Test]
    public function active_weekly_rules_project_every_eligible_date_in_the_horizon(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user, ['name' => 'Conta corrente']);
        $category = $this->dashboardCategory($user, 'expense', ['name' => 'Academia']);

        RecurringTransaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount_centavos' => 12_000,
            'description' => 'Academia',
            'frequency' => RecurrenceFrequency::Weekly,
            'start_date' => '2026-09-17',
            'eligibility_starts_on' => '2026-09-17',
            'schedule_cursor' => '2026-09-17',
            'state' => RecurrenceState::Active,
        ]);

        $response = $this->getJson('/api/v1/financial-dashboard/upcoming-activity')->assertOk();
        $data = $response->json('data');

        self::assertSame(
            ['2026-09-17', '2026-09-24', '2026-10-01', '2026-10-08', '2026-10-15'],
            array_column($data, 'expected_date'),
        );
        self::assertSame('recurring_occurrence', $data[0]['source_kind']);
        self::assertSame('expected', $data[4]['state']);
        self::assertSame(12_000, $data[4]['amount']['amount_centavos']);
    }

    #[Test]
    public function paused_and_ended_rules_never_project_expected_dates(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user);
        $category = $this->dashboardCategory($user, 'expense');

        foreach ([RecurrenceState::Paused, RecurrenceState::Ended] as $state) {
            RecurringTransaction::factory()->create([
                'user_id' => $user->id,
                'financial_account_id' => $account->id,
                'category_id' => $category->id,
                'type' => 'expense',
                'amount_centavos' => 15_000,
                'frequency' => RecurrenceFrequency::Monthly,
                'start_date' => '2026-09-20',
                'eligibility_starts_on' => '2026-09-20',
                'schedule_cursor' => '2026-09-20',
                'state' => $state,
            ]);
        }

        $this->getJson('/api/v1/financial-dashboard/upcoming-activity')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    #[Test]
    public function a_generated_occurrence_appears_once_and_suppresses_its_rule_date(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user);
        $category = $this->dashboardCategory($user, 'expense', ['name' => 'Internet']);

        $rule = RecurringTransaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount_centavos' => 19_900,
            'description' => 'Internet',
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => '2026-10-05',
            'eligibility_starts_on' => '2026-10-05',
            'schedule_cursor' => '2026-10-05',
            'state' => RecurrenceState::Active,
        ]);

        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'amount_centavos' => 19_900,
            'description' => 'Internet',
            'transaction_date' => '2026-10-05',
            'status' => TransactionStatus::Pending,
            'recurring_transaction_id' => $rule->id,
            'recurrence_scheduled_date' => '2026-10-05',
        ]);

        $response = $this->getJson('/api/v1/financial-dashboard/upcoming-activity')->assertOk();
        $data = $response->json('data');

        self::assertCount(1, $data);
        self::assertSame('pending_transaction', $data[0]['source_kind']);
        self::assertSame('2026-10-05', $data[0]['expected_date']);
    }

    #[Test]
    public function rule_end_dates_bound_the_projected_horizon(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user);
        $category = $this->dashboardCategory($user, 'income', ['name' => 'Aluguel']);

        RecurringTransaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'income',
            'amount_centavos' => 250_000,
            'description' => 'Aluguel',
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => '2026-09-20',
            'end_date' => '2026-09-30',
            'eligibility_starts_on' => '2026-09-20',
            'schedule_cursor' => '2026-09-20',
            'state' => RecurrenceState::Active,
        ]);

        $response = $this->getJson('/api/v1/financial-dashboard/upcoming-activity')->assertOk();

        self::assertSame(['2026-09-20'], array_column($response->json('data'), 'expected_date'));
        $response->assertJsonPath('data.0.type', 'income');
    }
}
