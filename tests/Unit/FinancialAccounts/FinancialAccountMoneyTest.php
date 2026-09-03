<?php

declare(strict_types=1);

namespace Tests\Unit\FinancialAccounts;

use App\Services\FinancialAccounts\FinancialAccountMoney;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FinancialAccountMoneyTest extends TestCase
{
    #[Test]
    public function it_supports_the_full_brazilian_real_centavo_range(): void
    {
        $this->assertTrue(FinancialAccountMoney::isSupported(-999_999_999_999));
        $this->assertTrue(FinancialAccountMoney::isSupported(0));
        $this->assertTrue(FinancialAccountMoney::isSupported(999_999_999_999));
    }

    #[Test]
    public function it_rejects_values_beyond_the_brazilian_real_range(): void
    {
        $this->assertFalse(FinancialAccountMoney::isSupported(-1_000_000_000_000));
        $this->assertFalse(FinancialAccountMoney::isSupported(1_000_000_000_000));
    }

    #[Test]
    public function it_rejects_unsupported_values_when_asserting(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FinancialAccountMoney::assertSupported(1_000_000_000_000);
    }
}
