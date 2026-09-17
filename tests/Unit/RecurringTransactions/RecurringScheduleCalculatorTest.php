<?php

declare(strict_types=1);

namespace Tests\Unit\RecurringTransactions;

use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\RecurringTransactions\RecurrencePausedReason;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionType;
use App\Models\RecurringTransaction;
use App\Services\RecurringTransactions\RecurringScheduleCalculator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecurringScheduleCalculatorTest extends TestCase
{
    private RecurringScheduleCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new RecurringScheduleCalculator;
    }

    #[Test]
    public function weekly_rules_follow_the_start_weekday(): void
    {
        $rule = $this->rule(['frequency' => RecurrenceFrequency::Weekly, 'start_date' => '2026-01-05']);

        self::assertSame('2026-01-12', $this->calculator->firstEligibleOnOrAfter($rule, '2026-01-06'));
        self::assertSame('2026-01-05', $this->calculator->firstEligibleOnOrAfter($rule, '2026-01-01'));
        self::assertSame(
            ['2026-02-02', '2026-02-09', '2026-02-16'],
            $this->calculator->datesBetween($rule, '2026-02-01', '2026-02-20'),
        );
    }

    #[Test]
    public function monthly_rules_use_month_end_when_the_ordinal_day_is_absent(): void
    {
        $rule = $this->rule(['frequency' => RecurrenceFrequency::Monthly, 'start_date' => '2026-01-31']);

        self::assertSame(
            ['2026-02-28', '2026-03-31', '2026-04-30'],
            $this->calculator->datesBetween($rule, '2026-02-01', '2026-04-30'),
        );
    }

    #[Test]
    public function yearly_rules_fall_back_to_28_february_outside_leap_years(): void
    {
        $rule = $this->rule(['frequency' => RecurrenceFrequency::Yearly, 'start_date' => '2024-02-29']);

        self::assertSame(
            ['2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29'],
            $this->calculator->datesBetween($rule, '2025-01-01', '2028-12-31'),
        );
    }

    #[Test]
    public function creation_anchor_and_end_date_bound_the_schedule(): void
    {
        $rule = $this->rule([
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => '2025-06-10',
            'eligibility_starts_on' => '2026-03-15',
            'end_date' => '2026-05-31',
        ]);

        self::assertSame(['2026-04-10', '2026-05-10'], $this->calculator->datesBetween($rule, '2026-01-01', '2026-06-30'));
        self::assertNull($this->calculator->firstEligibleOnOrAfter($rule, '2026-06-01'));
    }

    #[Test]
    public function next_expected_occurrence_skips_generated_dates_and_paused_rules(): void
    {
        $rule = $this->rule(['frequency' => RecurrenceFrequency::Monthly, 'start_date' => '2026-01-10']);
        $generated = ['2026-03-10' => true, '2026-04-10' => true];

        self::assertSame('2026-05-10', $this->calculator->nextExpectedOccurrence($rule, '2026-03-10', $generated));
        self::assertSame('2026-03-10', $this->calculator->nextExpectedOccurrence($rule, '2026-03-01'));

        $paused = $this->rule([
            'frequency' => RecurrenceFrequency::Monthly,
            'state' => RecurrenceState::Paused,
            'paused_reason' => RecurrencePausedReason::User,
        ]);
        self::assertNull($this->calculator->nextExpectedOccurrence($paused, '2026-03-01'));
    }

    /** @param array<string, mixed> $overrides */
    private function rule(array $overrides = []): RecurringTransaction
    {
        $start = $overrides['start_date'] ?? '2026-01-10';

        return new RecurringTransaction(array_merge([
            'user_id' => 1,
            'financial_account_id' => 1,
            'category_id' => 1,
            'type' => TransactionType::Expense->value,
            'amount_centavos' => 10_000,
            'currency_code' => 'BRL',
            'description' => 'Assinatura',
            'notes' => null,
            'frequency' => RecurrenceFrequency::Monthly->value,
            'start_date' => $start,
            'end_date' => null,
            'state' => RecurrenceState::Active->value,
            'paused_reason' => null,
            'eligibility_starts_on' => $start,
            'schedule_cursor' => $start,
            'ended_at' => null,
        ], $overrides));
    }
}
