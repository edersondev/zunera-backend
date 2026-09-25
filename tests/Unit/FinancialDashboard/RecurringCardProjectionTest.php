<?php

declare(strict_types=1);

namespace Tests\Unit\FinancialDashboard;

use App\Data\FinancialDashboard\DashboardPeriodData;
use App\Services\CreditCards\CreditCardBudgetProjectionService;
use App\Services\FinancialDashboard\DashboardRecentActivityService;
use App\Services\FinancialDashboard\DashboardSummaryService;
use App\Services\FinancialDashboard\DashboardUpcomingActivityService;
use App\Services\FinancialDashboard\RecurringCardExpenseProjection;
use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\RecurringTransactions\RecurringTransactionFeatureTestCase;
use Tests\Support\CreditCards\CreditCardFixtures;

final class RecurringCardProjectionTest extends RecurringTransactionFeatureTestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function recording_suppresses_the_same_expected_date_and_closed_spending_has_one_source(): void
    {
        $user = $this->cardSignIn('2026-09-01');
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $account = $this->ownedAccount($user, 100_000);
        $rule = $this->cardRule($user, $card, [
            'category_id' => $category->id,
            'amount_centavos' => 15_000,
            'start_date' => '2026-09-01',
            'eligibility_starts_on' => '2026-09-01',
            'schedule_cursor' => '2026-09-01',
        ]);
        $upcoming = app(DashboardUpcomingActivityService::class);
        self::assertCount(1, array_filter($upcoming->upcoming($user, '2026-09-01')['items'],
            static fn (array $item): bool => $item['expected_date'] === '2026-09-01'));

        app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-01');
        $purchase = $rule->cardOccurrences()->firstOrFail()->purchase;
        $statement = $purchase->installments()->firstOrFail()->statement;
        self::assertSame('2026-09-10', $statement->closing_date->toDateString());
        self::assertCount(0, array_filter($upcoming->upcoming($user, '2026-09-01')['items'],
            static fn (array $item): bool => $item['expected_date'] === '2026-09-01'));
        $recent = app(DashboardRecentActivityService::class)->recent($user);
        self::assertCount(1, $recent);
        self::assertSame($purchase->id, $recent[0]['model']->id);

        $budget = app(CreditCardBudgetProjectionService::class);
        $dashboard = app(RecurringCardExpenseProjection::class);
        self::assertSame(15_000, $budget->expectedByCategory($user, '2026-09-01', '2026-09-30')->get($category->id));
        self::assertSame(0, $dashboard->total($user, '2026-09-01', '2026-09-30'));

        $this->cardSignIn('2026-09-20', $user);
        self::assertSame(15_000, $budget->realizedByCategory($user, '2026-09-01', '2026-09-30')->get($category->id));
        self::assertSame(15_000, $dashboard->total($user, '2026-09-01', '2026-09-30'));
        self::assertSame(15_000, app(DashboardSummaryService::class)
            ->summary($user, DashboardPeriodData::custom('2026-09-01', '2026-09-30'))['realized_expenses_centavos']);

        $this->postJson('/api/v1/credit-card-statements/'.$statement->id.'/payments', [
            'financial_account_id' => $account->id,
            'amount_centavos' => 15_000,
            'payment_date' => '2026-09-17',
        ], ['Idempotency-Key' => 'projection-settlement'])->assertCreated();
        self::assertSame(15_000, $budget->realizedByCategory($user, '2026-09-01', '2026-09-30')->get($category->id));
        self::assertSame(15_000, $dashboard->total($user, '2026-09-01', '2026-09-30'));
        self::assertSame(15_000, app(DashboardSummaryService::class)
            ->summary($user, DashboardPeriodData::custom('2026-09-01', '2026-09-30'))['realized_expenses_centavos']);
    }
}
