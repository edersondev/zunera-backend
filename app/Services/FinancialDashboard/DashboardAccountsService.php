<?php

declare(strict_types=1);

namespace App\Services\FinancialDashboard;

use App\Enums\FinancialAccounts\AccountStatus;
use App\Models\FinancialAccount;
use App\Models\User;

/**
 * Active-account allocation. Archived accounts never appear because the current
 * overview describes today's position, not historical balances.
 */
final class DashboardAccountsService
{
    /** @return array<string, mixed> */
    public function overview(User $user): array
    {
        $accounts = FinancialAccount::query()
            ->where('user_id', $user->id)
            ->where('status', AccountStatus::Active)
            ->orderByDesc('current_balance_centavos')
            ->orderBy('id')
            ->get();

        $total = (int) $accounts->sum(fn (FinancialAccount $account): int => (int) $account->current_balance_centavos);

        $items = $accounts
            ->map(fn (FinancialAccount $account): array => [
                'account' => $account,
                'current_balance_centavos' => (int) $account->current_balance_centavos,
                'allocation_percent' => $total === 0
                    ? null
                    : round((int) $account->current_balance_centavos / $total * 100, 2),
            ])
            ->values()
            ->all();

        return [
            'current_total_balance_centavos' => $total,
            'currency_code' => 'BRL',
            'accounts' => $items,
        ];
    }
}
