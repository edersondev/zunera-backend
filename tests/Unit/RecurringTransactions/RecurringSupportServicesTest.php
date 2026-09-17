<?php

declare(strict_types=1);

namespace Tests\Unit\RecurringTransactions;

use App\Services\RecurringTransactions\RecurringDateRange;
use App\Services\RecurringTransactions\RecurringTextNormalizer;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecurringSupportServicesTest extends TestCase
{
    #[Test]
    public function dates_accept_both_supported_formats_within_the_supported_range(): void
    {
        self::assertSame('2026-09-14', RecurringDateRange::normalize('2026-09-14'));
        self::assertSame('2026-09-14', RecurringDateRange::normalize('14/09/2026'));
        self::assertSame('1900-01-01', RecurringDateRange::normalize('01/01/1900'));
        self::assertSame('2100-12-31', RecurringDateRange::normalize('2100-12-31'));
    }

    #[Test]
    public function invalid_dates_are_rejected_with_field_feedback(): void
    {
        foreach (['1899-12-31', '2101-01-01', '2026-02-30', '31/02/2026', 'not-a-date', ''] as $value) {
            try {
                RecurringDateRange::normalize($value);
                self::fail('Expected invalid date rejection for '.$value);
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('start_date', $exception->errors());
            }
        }
    }

    #[Test]
    public function business_date_uses_the_sao_paulo_calendar(): void
    {
        $expected = now(RecurringDateRange::BUSINESS_TIMEZONE)->toDateString();

        self::assertSame($expected, RecurringDateRange::businessDate());
        self::assertFalse(RecurringDateRange::isFuture($expected));
        self::assertTrue(RecurringDateRange::isFuture(now()->addDays(5)->toDateString()));
    }

    #[Test]
    public function text_normalizer_trims_blank_values_and_collapses_spacing(): void
    {
        self::assertNull(RecurringTextNormalizer::trimToNull(null));
        self::assertNull(RecurringTextNormalizer::trimToNull('   '));
        self::assertSame('Aluguel', RecurringTextNormalizer::trimToNull('  Aluguel  '));
        self::assertSame('Aluguel do mês', RecurringTextNormalizer::normalize('  Aluguel   do ', ' mês '));
    }
}
