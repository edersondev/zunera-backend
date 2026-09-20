<?php

declare(strict_types=1);

namespace Tests\Unit\CreditCards;

use App\Services\CreditCards\InstallmentAllocator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InstallmentAllocatorTest extends TestCase
{
    private InstallmentAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new InstallmentAllocator;
    }

    /**
     * Representative centavo/count pairs around remainder boundaries, the
     * 1..360 installment range, and single-centavo totals.
     *
     * @return array<string, array{0: int, 1: int, 2: list<int>}>
     */
    public static function allocationCases(): array
    {
        return [
            'single payment equals total' => [200_00, 1, [20_000]],
            'even six installments' => [1_200_00, 6, [20_000, 20_000, 20_000, 20_000, 20_000, 20_000]],
            'one centavo remainder on first' => [100_01, 3, [3_334, 3_334, 3_333]],
            'two centavo remainder on first two' => [100_02, 3, [3_334, 3_334, 3_334]],
            'four centavo spread over seven' => [100_00, 7, [1_429, 1_429, 1_429, 1_429, 1_428, 1_428, 1_428]],
            'single centavo single installment' => [1, 1, [1]],
            'smallest split centavo pair' => [2, 2, [1, 1]],
            'odd centavo split in two' => [3, 2, [2, 1]],
            'one hundred in three' => [100, 3, [34, 33, 33]],
            'thousand in three' => [1_000, 3, [334, 333, 333]],
            '360 installments of a large total' => [360_00, 360, array_fill(0, 360, 100)],
            '360 installments with remainder' => [400_00, 360, array_merge(array_fill(0, 40, 112), array_fill(0, 320, 111))],
            'prime total in five' => [10_003, 5, [2_001, 2_001, 2_001, 2_000, 2_000]],
            'single centavo per installment' => [5, 5, [1, 1, 1, 1, 1]],
            'maximum supported total in two' => [99_999_999_999, 2, [50_000_000_000, 49_999_999_999]],
        ];
    }

    #[Test]
    #[DataProvider('allocationCases')]
    public function allocates_exact_centavos_with_earliest_remainder(int $totalCentavos, int $installmentCount, array $expected): void
    {
        $installments = $this->allocator->allocate($totalCentavos, $installmentCount);

        $this->assertSame($expected, $installments);
        $this->assertCount($installmentCount, $installments);
        $this->assertSame($totalCentavos, array_sum($installments), 'Installment sum must equal the purchase total exactly.');
    }

    #[Test]
    public function every_allocation_between_one_and_three_hundred_centavos_stays_exact(): void
    {
        $checked = 0;

        for ($total = 1; $total <= 300; $total++) {
            $count = min($total, 360);
            $installments = $this->allocator->allocate($total, $count);

            $this->assertSame($total, array_sum($installments));
            $this->assertSame($count, count($installments));
            foreach ($installments as $amount) {
                $this->assertGreaterThan(0, $amount);
            }
            $this->assertGreaterThanOrEqual($installments[count($installments) - 1], $installments[0]);
            $checked++;
        }

        $this->assertSame(300, $checked);
    }

    #[Test]
    public function rejects_installment_counts_outside_the_supported_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->allocator->allocate(10_000, 361);
    }

    #[Test]
    public function rejects_zero_installment_count(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->allocator->allocate(10_000, 0);
    }

    #[Test]
    public function rejects_totals_that_cannot_fill_every_installment(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->allocator->allocate(3, 4);
    }

    #[Test]
    public function rejects_non_positive_totals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->allocator->allocate(0, 1);
    }

    #[Test]
    public function credit_allocations_follow_original_installment_sequence(): void
    {
        $installments = [
            ['id' => 1, 'amount_centavos' => 3_334, 'credited_centavos' => 0],
            ['id' => 2, 'amount_centavos' => 3_334, 'credited_centavos' => 0],
            ['id' => 3, 'amount_centavos' => 3_333, 'credited_centavos' => 0],
        ];

        $applications = $this->allocator->allocateCredit($installments, 4_000);

        $this->assertSame([
            ['id' => 1, 'amount_centavos' => 3_334],
            ['id' => 2, 'amount_centavos' => 666],
        ], $applications);
    }

    #[Test]
    public function credit_allocations_skip_already_credited_installments(): void
    {
        $installments = [
            ['id' => 1, 'amount_centavos' => 1_000, 'credited_centavos' => 1_000],
            ['id' => 2, 'amount_centavos' => 1_000, 'credited_centavos' => 400],
        ];

        $applications = $this->allocator->allocateCredit($installments, 600);

        $this->assertSame([['id' => 2, 'amount_centavos' => 600]], $applications);
    }

    #[Test]
    public function credit_allocations_cannot_exceed_the_uncredited_amount(): void
    {
        $installments = [['id' => 1, 'amount_centavos' => 1_000, 'credited_centavos' => 900]];

        $this->expectException(InvalidArgumentException::class);

        $this->allocator->allocateCredit($installments, 200);
    }
}
