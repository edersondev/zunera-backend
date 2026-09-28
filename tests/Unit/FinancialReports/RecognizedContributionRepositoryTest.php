<?php

declare(strict_types=1);

namespace Tests\Unit\FinancialReports;

use App\Enums\Transactions\TransactionStatus;
use App\Models\User;
use App\Repositories\FinancialReports\RecognizedContributionRepository;
use App\Services\FinancialReports\ReportPeriodResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FinancialReports\FinancialReportFixtures;
use Tests\TestCase;

final class RecognizedContributionRepositoryTest extends TestCase
{
    use FinancialReportFixtures;
    use RefreshDatabase;

    #[Test]
    public function ordinary_and_card_adjustments_are_signed_exactly_once_at_source_dates(): void
    {
        $user = $this->reportSignIn('2026-11-20');
        $account = $this->reportAccount($user);
        $income = $this->reportCategory($user, 'income');
        $expense = $this->reportCategory($user);
        $this->reportTransaction($user, $account, $income, 10_001, '2026-08-05');
        $this->reportTransaction($user, $account, $expense, 2_001, '2026-08-06');
        $purchase = $this->reportCardPurchase($user, $expense, 10_001, 3, '2026-08-05');
        $event = $this->recordCreditEvent($purchase, 5_001, ['event_date' => '2026-11-19']);

        $scope = app(ReportPeriodResolver::class)->resolve((int) $user->id, [
            'preset' => 'custom', 'from' => '2026-08-01', 'to' => '2026-10-31',
        ]);
        $rows = app(RecognizedContributionRepository::class)->query($scope)->get();

        self::assertSame(10_001, $rows->where('classification', 'income')->sum('signed_amount_centavos'));
        self::assertSame(7_001, $rows->where('classification', 'expense')->sum('signed_amount_centavos'));
        self::assertSame(3, $rows->where('source_kind', 'card_installment')->count());
        $adjustments = $rows->where('source_kind', 'card_credit_adjustment')->sortBy('recognized_date')->values();
        self::assertSame([-3_334, -1_667], $adjustments->pluck('signed_amount_centavos')->map(static fn ($value): int => (int) $value)->all());
        self::assertSame(['2026-08-10', '2026-09-10'], $adjustments->pluck('recognized_date')->all());
        self::assertSame([$event->id, $event->id], $adjustments->pluck('related_credit_event_id')->map(static fn ($value): int => (int) $value)->all());
        self::assertSame($purchase->id, (int) $adjustments->first()->related_purchase_id);
    }

    #[Test]
    public function pending_removed_foreign_transfer_and_goal_sources_do_not_contribute(): void
    {
        $user = $this->reportSignIn();
        $other = User::factory()->create();
        $account = $this->reportAccount($user);
        $destination = $this->reportAccount($user);
        $expense = $this->reportCategory($user);
        $this->reportTransaction($user, $account, $expense, 100, '2026-09-20');
        $this->reportTransaction($user, $account, $expense, 200, '2026-09-20', ['status' => TransactionStatus::Pending]);
        $this->reportTransaction($user, $account, $expense, 300, '2026-09-20', ['removed_at' => now()]);
        $this->reportTransaction($other, $this->reportAccount($other), $this->reportCategory($other), 400, '2026-09-20');
        $this->reportTransfer($user, $account, $destination, 500, '2026-09-20');
        $this->reportGoalActivity($user, $account, 'allocated', 600, '2026-09-20');
        $this->reportRecurringOccurrence($user, $account, $expense, 700, '2026-09-20');

        $scope = app(ReportPeriodResolver::class)->resolve((int) $user->id, [
            'preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-26',
        ]);
        $rows = app(RecognizedContributionRepository::class)->query($scope)->get();
        self::assertCount(1, $rows);
        self::assertSame(100, (int) $rows->sole()->signed_amount_centavos);
    }

    #[Test]
    public function account_category_and_type_filters_apply_to_all_eligible_sources(): void
    {
        $user = $this->reportSignIn();
        $account = $this->reportAccount($user);
        $otherAccount = $this->reportAccount($user);
        $category = $this->reportCategory($user);
        $otherCategory = $this->reportCategory($user);
        $this->reportTransaction($user, $account, $category, 100, '2026-09-20');
        $this->reportTransaction($user, $otherAccount, $category, 200, '2026-09-20');
        $this->reportTransaction($user, $account, $otherCategory, 300, '2026-09-20');
        $this->reportCardPurchase($user, $category, 400, 1, '2026-09-05');
        $resolver = app(ReportPeriodResolver::class);
        $repo = app(RecognizedContributionRepository::class);
        $base = ['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-26'];

        self::assertSame(100, $repo->query($resolver->resolve((int) $user->id, $base + [
            'account_id' => $account->id, 'category_id' => $category->id,
        ]))->get()->sum('signed_amount_centavos'));
        self::assertSame(700, $repo->query($resolver->resolve((int) $user->id, $base + [
            'category_id' => $category->id, 'transaction_type' => 'expense',
        ]))->get()->sum('signed_amount_centavos'));
        self::assertSame(0, $repo->query($resolver->resolve((int) $user->id, $base + [
            'category_id' => $category->id, 'transaction_type' => 'income',
        ]))->get()->sum('signed_amount_centavos'));
    }
}
