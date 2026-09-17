<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialDashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialDashboard\FinancialDashboardFixtures;
use Tests\TestCase;

final class DashboardAccountsTest extends TestCase
{
    use FinancialDashboardFixtures;
    use RefreshDatabase;

    #[Test]
    public function it_reports_active_accounts_with_their_signed_allocation_share(): void
    {
        $user = $this->dashboardSignIn();
        $this->dashboardAccount($user, ['name' => 'Conta corrente', 'current_balance_centavos' => 750_000]);
        $this->dashboardAccount($user, ['name' => 'Poupança', 'current_balance_centavos' => 250_000]);
        $this->dashboardArchivedAccount($user, ['name' => 'Conta antiga', 'current_balance_centavos' => 900_000]);

        $response = $this->getJson('/api/v1/financial-dashboard/accounts')->assertOk();

        $response->assertJsonPath('data.current_total_balance.amount_centavos', 1_000_000)
            ->assertJsonPath('data.accounts.0.account.name', 'Conta corrente')
            ->assertJsonPath('data.accounts.0.current_balance.amount_centavos', 750_000)
            ->assertJsonPath('data.accounts.0.allocation_percent', 75)
            ->assertJsonPath('data.accounts.1.account.name', 'Poupança')
            ->assertJsonPath('data.accounts.1.allocation_percent', 25);

        self::assertCount(2, $response->json('data.accounts'));
    }

    #[Test]
    public function opposing_active_balances_produce_signed_allocation_beyond_a_hundred_percent(): void
    {
        $user = $this->dashboardSignIn();
        $this->dashboardAccount($user, ['name' => 'Crédito', 'current_balance_centavos' => 200_000]);
        $this->dashboardAccount($user, ['name' => 'Dívida', 'current_balance_centavos' => -100_000]);

        $this->getJson('/api/v1/financial-dashboard/accounts')
            ->assertOk()
            ->assertJsonPath('data.current_total_balance.amount_centavos', 100_000)
            ->assertJsonPath('data.accounts.0.allocation_percent', 200)
            ->assertJsonPath('data.accounts.1.allocation_percent', -100);
    }

    #[Test]
    public function a_zero_combined_balance_leaves_allocation_unavailable(): void
    {
        $user = $this->dashboardSignIn();
        $this->dashboardAccount($user, ['name' => 'Crédito', 'current_balance_centavos' => 100_000]);
        $this->dashboardAccount($user, ['name' => 'Dívida', 'current_balance_centavos' => -100_000]);

        $this->getJson('/api/v1/financial-dashboard/accounts')
            ->assertOk()
            ->assertJsonPath('data.current_total_balance.amount_centavos', 0)
            ->assertJsonPath('data.accounts.0.allocation_percent', null)
            ->assertJsonPath('data.accounts.1.allocation_percent', null);
    }

    #[Test]
    public function a_user_without_active_accounts_receives_an_empty_overview(): void
    {
        $this->dashboardSignIn();

        $this->getJson('/api/v1/financial-dashboard/accounts')
            ->assertOk()
            ->assertJsonPath('data.current_total_balance.amount_centavos', 0)
            ->assertJsonPath('data.accounts', []);
    }
}
