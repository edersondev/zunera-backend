<?php

declare(strict_types=1);

namespace Tests\Unit\FinancialAccounts;

use App\Data\FinancialAccounts\UpdateFinancialAccountData;
use App\Exceptions\FinancialAccounts\FinancialAccountStateException;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\FinancialAccounts\FinancialAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinancialAccountUpdateRulesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_locks_initial_balance_updates_after_financial_movements_exist(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->withMovements()->create(['user_id' => $user->id]);

        $this->expectException(FinancialAccountStateException::class);

        app(FinancialAccountService::class)->update(
            $user,
            $account,
            new UpdateFinancialAccountData(['initial_balance_centavos' => 500]),
        );
    }

    #[Test]
    public function it_allows_initial_balance_updates_before_financial_movements_exist(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->create([
            'user_id' => $user->id,
            'initial_balance_centavos' => 1_000,
            'current_balance_centavos' => 1_000,
        ]);

        $updated = app(FinancialAccountService::class)->update(
            $user,
            $account,
            new UpdateFinancialAccountData(['initial_balance_centavos' => 2_500]),
        );

        $this->assertSame(2_500, $updated->initial_balance_centavos);
        $this->assertSame(2_500, $updated->current_balance_centavos);
    }
}
