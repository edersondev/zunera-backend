<?php

declare(strict_types=1);

namespace Tests\Feature\Transfers;

use App\Models\FinancialAccount;
use App\Models\User;
use Tests\TestCase;

abstract class TransferFeatureTestCase extends TestCase
{
    protected function signInUser(): User
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        return $user;
    }

    /** @return array{0: FinancialAccount, 1: FinancialAccount} */
    protected function ownedSides(User $user, int $sourceBalance = 500_000, int $destinationBalance = 200_000): array
    {
        return [
            FinancialAccount::factory()->create([
                'user_id' => $user->id,
                'initial_balance_centavos' => $sourceBalance,
                'current_balance_centavos' => $sourceBalance,
            ]),
            FinancialAccount::factory()->create([
                'user_id' => $user->id,
                'initial_balance_centavos' => $destinationBalance,
                'current_balance_centavos' => $destinationBalance,
            ]),
        ];
    }

    /** @return array<string, mixed> */
    protected function payload(FinancialAccount $source, FinancialAccount $destination, int $amount = 100_000, array $overrides = []): array
    {
        return array_merge([
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => $amount,
            'transfer_date' => today()->toDateString(),
            'description' => 'Transferência teste',
            'notes' => 'Entre contas próprias',
        ], $overrides);
    }
}
