<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialDashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialDashboard\FinancialDashboardFixtures;
use Tests\TestCase;

final class FinancialDashboardFoundationTest extends TestCase
{
    use FinancialDashboardFixtures;
    use RefreshDatabase;

    #[Test]
    public function every_dashboard_route_requires_an_authenticated_owner(): void
    {
        foreach ($this->dashboardPaths() as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }

    #[Test]
    public function summary_defaults_to_the_current_month_through_the_business_date(): void
    {
        $this->dashboardSignIn();

        $this->getJson('/api/v1/financial-dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.period.preset', 'current_month')
            ->assertJsonPath('data.period.from', '2026-09-01')
            ->assertJsonPath('data.period.to', '2026-09-17')
            ->assertJsonPath('data.current_total_balance.currency_code', 'BRL')
            ->assertJsonPath('data.realized_income.amount_centavos', 0)
            ->assertJsonPath('data.realized_expenses.amount_centavos', 0)
            ->assertJsonPath('data.financial_result.amount_centavos', 0);
    }

    #[Test]
    public function previous_month_preset_covers_its_whole_calendar_month(): void
    {
        $this->dashboardSignIn();

        $this->getJson('/api/v1/financial-dashboard/summary?preset=previous_month')
            ->assertOk()
            ->assertJsonPath('data.period.preset', 'previous_month')
            ->assertJsonPath('data.period.from', '2026-08-01')
            ->assertJsonPath('data.period.to', '2026-08-31');
    }

    #[Test]
    public function custom_preset_requires_both_inclusive_boundaries(): void
    {
        $this->dashboardSignIn();

        $this->getJson('/api/v1/financial-dashboard/summary?preset=custom&from=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson('/api/v1/financial-dashboard/summary?preset=custom&to=2026-09-10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');
    }

    #[Test]
    public function custom_preset_rejects_an_inverted_or_unusable_range(): void
    {
        $this->dashboardSignIn();

        $this->getJson('/api/v1/financial-dashboard/summary?preset=custom&from=2026-09-10&to=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson('/api/v1/financial-dashboard/summary?preset=custom&from=1800-01-01&to=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');

        $this->getJson('/api/v1/financial-dashboard/summary?preset=daily')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preset');
    }

    #[Test]
    public function custom_period_validation_applies_to_every_period_projection(): void
    {
        $this->dashboardSignIn();

        foreach (['summary', 'expense-distribution', 'evolution'] as $section) {
            $this->getJson('/api/v1/financial-dashboard/'.$section.'?preset=custom&from=2026-09-01')
                ->assertUnprocessable()
                ->assertJsonValidationErrors('to');
        }
    }

    #[Test]
    public function a_foreign_owner_sees_empty_projections_instead_of_other_users_data(): void
    {
        $user = User::factory()->create();
        $this->dashboardAccount($user, ['current_balance_centavos' => 500_000]);
        $this->dashboardTransaction($user, 'income', [
            'amount_centavos' => 100_000,
            'transaction_date' => '2026-09-10',
        ]);

        $this->dashboardSignIn();

        $this->getJson('/api/v1/financial-dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.current_total_balance.amount_centavos', 0)
            ->assertJsonPath('data.realized_income.amount_centavos', 0);
    }

    /** @return list<string> */
    private function dashboardPaths(): array
    {
        return [
            '/api/v1/financial-dashboard/summary',
            '/api/v1/financial-dashboard/accounts',
            '/api/v1/financial-dashboard/expense-distribution',
            '/api/v1/financial-dashboard/evolution',
            '/api/v1/financial-dashboard/recent-activity',
            '/api/v1/financial-dashboard/upcoming-activity',
        ];
    }
}
