<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialReports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialReports\FinancialReportFixtures;
use Tests\TestCase;

final class FinancialReportAuthorizationTest extends TestCase
{
    use FinancialReportFixtures;
    use RefreshDatabase;

    #[Test]
    public function both_report_reads_require_authentication(): void
    {
        $this->getJson('/api/v1/financial-reports')->assertUnauthorized();
        $this->getJson('/api/v1/financial-reports/contributions?metric=realized_income')->assertUnauthorized();
    }

    #[Test]
    public function foreign_scope_id_is_not_disclosed_and_owned_archived_identity_remains_valid(): void
    {
        $user = $this->reportSignIn();
        $other = User::factory()->create();
        $foreignAccount = $this->reportAccount($other);
        $foreignCategory = $this->reportCategory($other);
        $archivedAccount = $this->reportAccount($user, ['status' => 'archived', 'archived_at' => now()]);
        $archivedCategory = $this->reportCategory($user, 'expense', ['status' => 'archived', 'archived_at' => now()]);

        foreach ([
            ['account_id' => $foreignAccount->id],
            ['category_id' => $foreignCategory->id],
        ] as $filter) {
            $query = http_build_query($filter);
            $this->getJson('/api/v1/financial-reports?'.$query)->assertNotFound()
                ->assertDontSee($foreignAccount->name)->assertDontSee($foreignCategory->name);
            $this->getJson('/api/v1/financial-reports/contributions?metric=realized_expenses&'.$query)->assertNotFound();
        }

        $this->getJson('/api/v1/financial-reports?account_id='.$archivedAccount->id.'&category_id='.$archivedCategory->id)
            ->assertOk()->assertJsonPath('data.scope.filters.account_id', $archivedAccount->id)
            ->assertJsonPath('data.scope.filters.category_id', $archivedCategory->id);
    }

    #[Test]
    public function invalid_period_and_unknown_filter_inputs_return_validation_errors(): void
    {
        $this->reportSignIn();
        foreach ([
            ['preset' => 'nonsense'],
            ['preset' => 'custom', 'from' => '2026-09-01'],
            ['preset' => 'custom', 'from' => '2026-09-31', 'to' => '2026-10-01'],
            ['preset' => 'custom', 'from' => '2026-09-20', 'to' => '2026-09-01'],
            ['preset' => 'custom', 'from' => '1899-12-31', 'to' => '1900-01-01'],
            ['preset' => 'current_month', 'from' => '2026-09-01'],
            ['preset' => 'current_month', 'month' => '2026-08'],
            ['preset' => 'historical_month'],
            ['preset' => 'historical_month', 'month' => '2026-13'],
            ['preset' => 'historical_month', 'month' => '2026-09'],
            ['preset' => 'historical_month', 'month' => '2026-10'],
            ['preset' => 'historical_month', 'month' => '2026-08', 'to' => '2026-08-31'],
            ['transaction_type' => 'transfer'],
            ['unexpected' => '1'],
        ] as $query) {
            $this->getJson('/api/v1/financial-reports?'.http_build_query($query))->assertUnprocessable();
        }
    }

    #[Test]
    public function incompatible_owned_category_and_type_are_an_empty_filter_result(): void
    {
        $user = $this->reportSignIn();
        $category = $this->reportCategory($user, 'income');

        $this->getJson('/api/v1/financial-reports?category_id='.$category->id.'&transaction_type=expense')
            ->assertOk()
            ->assertJsonPath('data.summary.realized_income.amount_centavos', 0)
            ->assertJsonPath('data.summary.realized_expenses.amount_centavos', 0)
            ->assertJsonPath('data.empty_states.no_filter_matches', true);
    }
}
