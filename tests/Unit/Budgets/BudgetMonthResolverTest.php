<?php

declare(strict_types=1);

namespace Tests\Unit\Budgets;

use App\Services\Budgets\BudgetMonthResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BudgetMonthResolverTest extends TestCase
{
    private BudgetMonthResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new BudgetMonthResolver;
    }

    #[Test]
    public function it_resolves_full_calendar_month_boundaries(): void
    {
        self::assertSame('2026-09-01', $this->resolver->from(2026, 9));
        self::assertSame('2026-09-30', $this->resolver->to(2026, 9));
        self::assertSame('2026-02-01', $this->resolver->from(2026, 2));
        self::assertSame('2026-02-28', $this->resolver->to(2026, 2));
        self::assertSame('2026-12-31', $this->resolver->to(2026, 12));
        self::assertSame('2027-01-31', $this->resolver->to(2027, 1));
    }

    #[Test]
    public function it_returns_the_contract_period_shape(): void
    {
        self::assertSame(
            ['year' => 2026, 'month' => 9, 'from' => '2026-09-01', 'to' => '2026-09-30'],
            $this->resolver->period(2026, 9),
        );
    }

    #[Test]
    public function it_labels_ended_current_and_future_months_from_the_business_date(): void
    {
        $businessDate = CarbonImmutable::parse('2026-09-17 10:00:00', 'America/Sao_Paulo');

        self::assertTrue($this->resolver->isEnded(2026, 8, $businessDate));
        self::assertFalse($this->resolver->isEnded(2026, 9, $businessDate));
        self::assertFalse($this->resolver->isEnded(2026, 10, $businessDate));

        // The last day of a month still belongs to that month.
        self::assertFalse($this->resolver->isEnded(2026, 9, CarbonImmutable::parse('2026-09-30 23:30:00', 'America/Sao_Paulo')));
        self::assertTrue($this->resolver->isEnded(2026, 9, CarbonImmutable::parse('2026-10-01 00:30:00', 'America/Sao_Paulo')));
    }
}
