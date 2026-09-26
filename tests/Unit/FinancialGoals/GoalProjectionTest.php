<?php

declare(strict_types=1);

namespace Tests\Unit\FinancialGoals;

use App\Services\FinancialGoals\FinancialGoalQueryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoalProjectionTest extends TestCase
{
    /** @return array<string, array{int, int, int, int, float}> */
    public static function amounts(): array
    {
        return [
            'zero' => [10_000, 0, 10_000, 0, 0.0],
            'fractional' => [300, 1, 299, 0, 0.3333333333333333],
            'overfunded' => [100, 125, 0, 25, 125.0],
            'max target' => [999_999_999_999, 999_999_999_999, 0, 0, 100.0],
        ];
    }

    #[DataProvider('amounts')]
    public function test_progress_and_remaining_use_centavos(int $target, int $allocated, int $remaining, int $excess, float $progress): void
    {
        $projection = FinancialGoalQueryService::progress($target, $allocated);
        self::assertSame($remaining, $projection['remaining_centavos']);
        self::assertSame($excess, $projection['excess_centavos']);
        self::assertEqualsWithDelta($progress, $projection['progress_percentage'], 0.00000001);
    }
}
