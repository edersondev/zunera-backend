<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Models\CreditCardStatementPayment;
use App\Models\FinancialAccount;

/**
 * Applies the paying-account side of statement settlement. Effective payments
 * debit the account like an ordinary expense and may leave it negative; pending
 * or removed payments have no balance effect.
 */
final class CreditCardPaymentAccountReconciler
{
    public function reconcile(?CreditCardStatementPayment $previous, ?CreditCardStatementPayment $current): void
    {
        $effects = [];
        foreach ([[$previous, -1], [$current, 1]] as [$payment, $direction]) {
            if (! $payment instanceof CreditCardStatementPayment || ! $payment->countsTowardBalance()) {
                continue;
            }

            $accountId = (int) $payment->financial_account_id;
            $effects[$accountId] = ($effects[$accountId] ?? 0) + ($direction * -$payment->amount_centavos);
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
            $account->has_financial_movements = true;
            $account->save();
        }
    }
}
