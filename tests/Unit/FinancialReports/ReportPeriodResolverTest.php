<?php

declare(strict_types=1);

namespace Tests\Unit\FinancialReports;

use App\Services\FinancialReports\ReportPeriodResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReportPeriodResolverTest extends TestCase
{
    #[Test]
    public function quick_choices_use_sao_paulo_business_dates_and_completed_calendar_units(): void
    {
        $resolver = app(ReportPeriodResolver::class);
        $today = CarbonImmutable::parse('2026-09-26 00:30:00', 'America/Sao_Paulo');
        $cases = [
            'current_month' => ['2026-09-01', '2026-09-26', '2026-08-01', '2026-08-26'],
            'previous_month' => ['2026-08-01', '2026-08-31', '2026-07-01', '2026-07-31'],
            'current_year' => ['2026-01-01', '2026-09-26', '2025-01-01', '2025-09-26'],
            'previous_year' => ['2025-01-01', '2025-12-31', '2024-01-01', '2024-12-31'],
        ];

        foreach ($cases as $preset => [$from, $to, $priorFrom, $priorTo]) {
            $scope = $resolver->resolve(7, ['preset' => $preset], $today);
            self::assertSame([$from, $to], [$scope->currentFrom, $scope->currentTo]);
            self::assertSame([$priorFrom, $priorTo], [$scope->previousFrom, $scope->previousTo]);
        }
    }

    #[Test]
    public function selected_completed_month_uses_full_prior_month_but_custom_uses_equal_days(): void
    {
        $resolver = app(ReportPeriodResolver::class);
        $today = CarbonImmutable::parse('2026-09-26', 'America/Sao_Paulo');
        $historical = $resolver->resolve(7, ['preset' => 'historical_month', 'month' => '2026-07'], $today);
        self::assertSame('2026-07-01', $historical->currentFrom);
        self::assertSame('2026-07-31', $historical->currentTo);
        self::assertSame('2026-06-01', $historical->previousFrom);
        self::assertSame('2026-06-30', $historical->previousTo);
        self::assertSame('unequal_duration', $resolver->percentUnavailableReason($historical, 100, 50));

        $custom = $resolver->resolve(7, ['preset' => 'custom', 'from' => '2026-07-01', 'to' => '2026-07-31'], $today);
        self::assertSame('2026-05-31', $custom->previousFrom);
        self::assertSame('2026-06-30', $custom->previousTo);
        self::assertNull($resolver->percentUnavailableReason($custom, 100, 50));
    }

    #[Test]
    public function short_month_and_leap_day_caps_disable_percent_when_days_differ(): void
    {
        $resolver = app(ReportPeriodResolver::class);
        $march = $resolver->resolve(7, ['preset' => 'current_month'], CarbonImmutable::parse('2025-03-31', 'America/Sao_Paulo'));
        self::assertSame('2025-02-28', $march->previousTo);
        self::assertSame(31, $march->currentDayCount());
        self::assertSame(28, $march->previousDayCount());
        self::assertSame('unequal_duration', $resolver->percentUnavailableReason($march, 100, 50));

        $leap = $resolver->resolve(7, ['preset' => 'current_year'], CarbonImmutable::parse('2024-02-29', 'America/Sao_Paulo'));
        self::assertSame('2023-02-28', $leap->previousTo);
        self::assertSame('unequal_duration', $resolver->percentUnavailableReason($leap, 100, 50));
    }

    #[Test]
    public function zero_negative_and_sign_crossing_values_never_yield_unsafe_percentages(): void
    {
        $resolver = app(ReportPeriodResolver::class);
        $scope = $resolver->resolve(7, ['preset' => 'custom', 'from' => '2026-08-15', 'to' => '2026-09-14']);
        self::assertSame('2026-07-15', $scope->previousFrom);
        self::assertSame('2026-08-14', $scope->previousTo);
        self::assertSame('previous_nonpositive', $resolver->percentUnavailableReason($scope, 100, 0));
        self::assertSame('previous_nonpositive', $resolver->percentUnavailableReason($scope, 0, 0));
        self::assertSame('previous_nonpositive', $resolver->percentUnavailableReason($scope, 100, -50));
        self::assertSame('sign_crossing', $resolver->percentUnavailableReason($scope, -50, 100));
        self::assertNull($resolver->percentUnavailableReason($scope, 0, 100));
    }
}
