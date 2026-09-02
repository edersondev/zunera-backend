<?php

declare(strict_types=1);

namespace Tests\Unit\FinancialAccounts;

use App\Services\FinancialAccounts\FinancialAccountNameNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FinancialAccountNameNormalizerTest extends TestCase
{
    #[Test]
    public function it_trims_and_collapses_internal_spaces(): void
    {
        $this->assertSame(
            'conta principal',
            FinancialAccountNameNormalizer::normalize('  Conta   Principal  '),
        );
    }

    #[Test]
    public function it_ignores_case_and_accents_for_comparison(): void
    {
        $this->assertSame(
            FinancialAccountNameNormalizer::normalize('NU SALÁRIO'),
            FinancialAccountNameNormalizer::normalize('nu salario'),
        );
    }
}
