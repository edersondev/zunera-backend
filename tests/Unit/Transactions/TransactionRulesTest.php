<?php

declare(strict_types=1);

namespace Tests\Unit\Transactions;

use App\Services\Transactions\TransactionDateRange;
use App\Services\Transactions\TransactionMoney;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TransactionRulesTest extends TestCase
{
    #[Test]
    public function amount_bounds_are_positive_integer_centavos(): void
    {
        self::assertSame(1, TransactionMoney::MIN_CENTAVOS);
        self::assertSame(99_999_999_999, TransactionMoney::MAX_CENTAVOS);
    }

    #[Test]
    public function accepts_iso_and_brazilian_dates_only_inside_supported_range(): void
    {
        self::assertSame('2026-09-11', TransactionDateRange::normalize('11/09/2026'));
        self::assertSame('2026-09-11', TransactionDateRange::normalize('2026-09-11'));
        self::assertTrue(TransactionDateRange::isFuture(today()->addDay()));
    }
}
