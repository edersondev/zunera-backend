<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\Models\FinancialAccount;
use App\Models\Transaction;

final class TransactionBalanceReconciler
{
    public function reconcile(?Transaction $previous, ?Transaction $result): void
    {
        $effects = [];
        foreach ([[$previous, -1], [$result, 1]] as [$transaction, $direction]) {
            if (! $transaction instanceof Transaction || ! $transaction->countsTowardBalance()) {
                continue;
            }

            $accountId = (int) $transaction->financial_account_id;
            $effects[$accountId] = ($effects[$accountId] ?? 0) + ($direction * $transaction->balanceEffect());
        }

        if ($effects === []) {
            return;
        }

        $accounts = FinancialAccount::query()
            ->whereIn('id', array_keys($effects))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($effects as $accountId => $effect) {
            /** @var FinancialAccount $account */
            $account = $accounts->get($accountId);
            $account->current_balance_centavos += $effect;
            $account->save();
        }
    }
}
