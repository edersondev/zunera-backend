<?php

declare(strict_types=1);

namespace Tests\Unit\Transfers;

use App\Data\Transfers\CreateTransferData;
use App\Enums\Transfers\TransferStatus;
use App\Exceptions\Transfers\TransferStateException;
use App\Models\FinancialAccount;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Transfers\TransferDateRange;
use App\Services\Transfers\TransferMoney;
use App\Services\Transfers\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TransferRulesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function money_bounds_and_brazilian_parsing_are_exact(): void
    {
        self::assertSame(1, TransferMoney::MIN_CENTAVOS);
        self::assertSame(99_999_999_999, TransferMoney::MAX_CENTAVOS);
        self::assertFalse(TransferMoney::isSupported(0));
        self::assertTrue(TransferMoney::isSupported(1));
        self::assertFalse(TransferMoney::isSupported(100_000_000_000));
        self::assertSame(123_456, TransferMoney::fromBrl('R$ 1.234,56'));
        self::assertSame(123_456, TransferMoney::fromBrl('1.234,56'));
        self::assertSame(5_000, TransferMoney::fromBrl('R$ 50,00'));
        self::assertNull(TransferMoney::fromBrl('R$'));
    }

    #[Test]
    public function dates_accept_iso_and_brazilian_input_inside_documented_bounds(): void
    {
        self::assertSame('2026-09-13', TransferDateRange::normalize('2026-09-13'));
        self::assertSame('2026-09-13', TransferDateRange::normalize('13/09/2026'));
        self::assertFalse(TransferDateRange::isFuture('2026-09-13'));

        foreach (['1899-12-31', '2101-01-01', '31/12/2101', '13/13/2026', 'hoje'] as $value) {
            try {
                TransferDateRange::normalize($value);
                self::fail("Expected [{$value}] to be rejected.");
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('transfer_date', $exception->errors());
            }
        }
    }

    #[Test]
    public function future_creation_defaults_to_pending_and_never_reserves_funds(): void
    {
        $user = User::factory()->create();
        $source = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 100_000]);
        $destination = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 100_000]);

        $result = app(TransferService::class)->create($user, new CreateTransferData(
            userId: (int) $user->id,
            sourceFinancialAccountId: (int) $source->id,
            destinationFinancialAccountId: (int) $destination->id,
            amountCentavos: 90_000,
            transferDate: today()->addMonth()->toDateString(),
            status: null,
            description: null,
            notes: null,
        ), 'unit-future');

        self::assertSame('pending', $result['transfer']->status->value);
        self::assertSame(100_000, $source->refresh()->current_balance_centavos);
        self::assertSame(100_000, $destination->refresh()->current_balance_centavos);
    }

    #[Test]
    public function explicit_future_effective_creation_is_rejected_without_effect(): void
    {
        $user = User::factory()->create();
        $source = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 100_000]);
        $destination = FinancialAccount::factory()->create(['user_id' => $user->id, 'current_balance_centavos' => 100_000]);

        try {
            app(TransferService::class)->create($user, new CreateTransferData(
                userId: (int) $user->id,
                sourceFinancialAccountId: (int) $source->id,
                destinationFinancialAccountId: (int) $destination->id,
                amountCentavos: 10_000,
                transferDate: today()->addWeek()->toDateString(),
                status: TransferStatus::Effective,
                description: null,
                notes: null,
            ), 'unit-future-effective');
            self::fail('Expected the future effective transfer to be rejected.');
        } catch (TransferStateException $exception) {
            self::assertSame('effective_future_date', $exception->errorCode());
            self::assertSame(422, $exception->statusCode());
        }

        self::assertSame(0, Transfer::count());
        self::assertSame(100_000, $source->refresh()->current_balance_centavos);
        self::assertSame(100_000, $destination->refresh()->current_balance_centavos);
    }
}
