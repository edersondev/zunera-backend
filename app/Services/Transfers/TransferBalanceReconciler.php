<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Exceptions\Transfers\TransferStateException;
use App\Models\FinancialAccount;
use App\Models\Transfer;
use App\Services\FinancialAccounts\FinancialAccountMoney;
use Illuminate\Database\Eloquent\Collection;

final class TransferBalanceReconciler
{
    /**
     * Apply the exact delta between the previous and resulting transfer state to every
     * affected account inside the caller's transaction.
     *
     * @return array<int, int> proposed balances keyed by account identifier
     */
    public function reconcile(?Transfer $previous, ?Transfer $result): array
    {
        $effects = $this->effects($previous, $result);
        if ($effects === []) {
            return [];
        }

        /** @var Collection<int, FinancialAccount> $accounts */
        $accounts = FinancialAccount::query()
            ->whereIn('id', array_keys($effects))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $proposed = [];
        foreach ($accounts as $accountId => $account) {
            /** @var FinancialAccount $account */
            $proposed[$accountId] = (int) $account->current_balance_centavos + ($effects[$accountId] ?? 0);
        }

        $this->assertProposedBalances($proposed, $result);

        foreach ($proposed as $accountId => $balance) {
            /** @var FinancialAccount $account */
            $account = $accounts->get($accountId);
            $account->current_balance_centavos = $balance;
            $account->save();
        }

        return $proposed;
    }

    /** @param array<int, int> $proposed */
    private function assertProposedBalances(array $proposed, ?Transfer $result): void
    {
        foreach ($proposed as $balance) {
            if (! FinancialAccountMoney::isSupported($balance)) {
                throw TransferStateException::accountBalanceOutOfRange();
            }
        }

        if (! $result instanceof Transfer || ! $result->countsTowardBalance()) {
            return;
        }

        $sourceId = (int) $result->source_financial_account_id;
        if (($proposed[$sourceId] ?? 0) < 0) {
            throw TransferStateException::insufficientSourceBalance();
        }
    }

    /** @return array<int, int> */
    private function effects(?Transfer $previous, ?Transfer $result): array
    {
        $effects = [];
        foreach ([[$previous, -1], [$result, 1]] as [$transfer, $direction]) {
            if (! $transfer instanceof Transfer || ! $transfer->countsTowardBalance()) {
                continue;
            }

            $sourceId = (int) $transfer->source_financial_account_id;
            $destinationId = (int) $transfer->destination_financial_account_id;
            $amount = (int) $transfer->amount_centavos;

            $effects[$sourceId] = ($effects[$sourceId] ?? 0) - ($direction * $amount);
            $effects[$destinationId] = ($effects[$destinationId] ?? 0) + ($direction * $amount);
        }

        return $effects;
    }
}
