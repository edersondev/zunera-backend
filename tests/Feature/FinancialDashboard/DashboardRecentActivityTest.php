<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialDashboard;

use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\Transactions\TransactionStatus;
use App\Models\RecurringTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialDashboard\FinancialDashboardFixtures;
use Tests\TestCase;

final class DashboardRecentActivityTest extends TestCase
{
    use FinancialDashboardFixtures;
    use RefreshDatabase;

    #[Test]
    public function it_returns_every_eligible_movement_when_fewer_than_ten_exist(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user);

        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 70_000,
            'transaction_date' => '2026-09-10',
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 20_000,
            'transaction_date' => '2026-09-12',
        ]);

        $response = $this->getJson('/api/v1/financial-dashboard/recent-activity')->assertOk();

        self::assertCount(2, $response->json('data'));
        $response->assertJsonPath('data.0.movement_kind', 'expense')
            ->assertJsonPath('data.0.movement_date', '2026-09-12')
            ->assertJsonPath('data.0.amount.amount_centavos', 20_000)
            ->assertJsonPath('data.0.amount.currency_code', 'BRL')
            ->assertJsonPath('data.0.category.classification', 'expense')
            ->assertJsonPath('data.1.movement_kind', 'income');
    }

    #[Test]
    public function it_keeps_only_the_ten_newest_movements_regardless_of_caller_limits(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user);
        $destination = $this->dashboardAccount($user);

        for ($index = 1; $index <= 9; $index++) {
            $this->dashboardTransaction($user, 'expense', [
                'financial_account_id' => $account->id,
                'amount_centavos' => 1_000 * $index,
                'transaction_date' => sprintf('2026-09-%02d', $index),
            ]);
        }
        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 10_000,
            'transaction_date' => '2026-09-10',
        ]);
        $this->dashboardTransfer($user, [
            'source_financial_account_id' => $account->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 30_000,
            'transfer_date' => '2026-09-11',
        ]);
        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 12_000,
            'transaction_date' => '2026-09-12',
        ]);

        $response = $this->getJson('/api/v1/financial-dashboard/recent-activity?limit=1')->assertOk();
        $data = $response->json('data');

        self::assertCount(10, $data);
        self::assertSame('2026-09-12', $data[0]['movement_date']);
        self::assertSame('transfer', $data[1]['movement_kind']);
        self::assertSame('2026-09-11', $data[1]['movement_date']);
        self::assertSame('2026-09-10', $data[2]['movement_date']);
        self::assertSame('2026-09-09', $data[3]['movement_date']);
        self::assertSame('2026-09-03', $data[9]['movement_date']);
    }

    #[Test]
    public function transfers_carry_both_sides_and_never_a_category_or_recurrence_source(): void
    {
        $user = $this->dashboardSignIn();
        $source = $this->dashboardAccount($user, ['name' => 'Origem']);
        $destination = $this->dashboardAccount($user, ['name' => 'Destino']);
        $this->dashboardTransfer($user, [
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 44_000,
            'transfer_date' => '2026-09-14',
            'description' => 'Reserva',
        ]);

        $this->getJson('/api/v1/financial-dashboard/recent-activity')
            ->assertOk()
            ->assertJsonPath('data.0.movement_kind', 'transfer')
            ->assertJsonPath('data.0.source_account.name', 'Origem')
            ->assertJsonPath('data.0.destination_account.name', 'Destino')
            ->assertJsonPath('data.0.category', null)
            ->assertJsonPath('data.0.recurrence_source', null);
    }

    #[Test]
    public function pending_and_recurrence_sourced_records_stay_labelled_and_removed_records_disappear(): void
    {
        $user = $this->dashboardSignIn();
        $account = $this->dashboardAccount($user);
        $rule = RecurringTransaction::factory()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $this->dashboardCategory($user)->id,
            'type' => 'expense',
            'amount_centavos' => 9_900,
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => '2026-09-15',
            'eligibility_starts_on' => '2026-09-15',
            'schedule_cursor' => '2026-09-15',
        ]);

        $this->dashboardTransaction($user, 'expense', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 9_900,
            'transaction_date' => '2026-09-15',
            'status' => TransactionStatus::Pending,
            'recurring_transaction_id' => $rule->id,
            'recurrence_scheduled_date' => '2026-09-15',
        ]);
        $this->dashboardTransaction($user, 'income', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 66_000,
            'transaction_date' => '2026-09-16',
            'removed_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/financial-dashboard/recent-activity')->assertOk();

        self::assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.recurrence_source.id', $rule->id)
            ->assertJsonPath('data.0.recurrence_source.scheduled_date', '2026-09-15')
            ->assertJsonPath('data.0.account.id', $account->id)
            ->assertJsonPath('data.0.category.classification', 'expense');
    }
}
