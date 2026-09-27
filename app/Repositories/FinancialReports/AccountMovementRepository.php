<?php

declare(strict_types=1);

namespace App\Repositories\FinancialReports;

use App\Data\FinancialReports\ReportScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Effective transfers and statement settlements, separate from report money. */
final class AccountMovementRepository
{
    public function query(ReportScope $scope, string $whichPeriod = 'current'): Builder
    {
        $period = $scope->period($whichPeriod);
        $in = $this->transferSide($scope, $period['from'], $period['to'], true);
        $out = $this->transferSide($scope, $period['from'], $period['to'], false);
        $payments = $this->settlements($scope, $period['from'], $period['to']);

        $query = DB::query()->fromSub($in->unionAll($out)->unionAll($payments), 'movement')
            ->join('financial_accounts as account', 'account.id', '=', 'movement.account_id')
            ->where('account.user_id', $scope->userId)
            ->select('movement.*')
            ->selectRaw('account.name as account_name, account.account_type, account.status as account_status');

        if ($scope->categoryId !== null || $scope->transactionType !== null) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function transferSide(ReportScope $scope, string $from, string $to, bool $incoming): Builder
    {
        $account = $incoming ? 'destination_financial_account_id' : 'source_financial_account_id';
        $kind = $incoming ? 'transfer_in' : 'transfer_out';

        return DB::table('transfers as transfer')
            ->where('transfer.user_id', $scope->userId)
            ->where('transfer.status', 'effective')
            ->whereNull('transfer.removed_at')
            ->whereDate('transfer.transfer_date', '>=', $from)
            ->whereDate('transfer.transfer_date', '<=', $to)
            ->when($scope->accountId !== null, fn (Builder $query) => $query->where('transfer.'.$account, $scope->accountId))
            ->selectRaw("'transfer' as source_kind, transfer.id as source_id, transfer.transfer_date as recognized_date, '{$kind}' as classification, transfer.{$account} as account_id, transfer.amount_centavos as signed_amount_centavos, transfer.description, NULL as related_statement_id");
    }

    private function settlements(ReportScope $scope, string $from, string $to): Builder
    {
        return DB::table('credit_card_statement_payments as payment')
            ->where('payment.user_id', $scope->userId)
            ->where('payment.status', 'effective')
            ->whereNull('payment.removed_at')
            ->whereDate('payment.payment_date', '>=', $from)
            ->whereDate('payment.payment_date', '<=', $to)
            ->when($scope->accountId !== null, fn (Builder $query) => $query->where('payment.financial_account_id', $scope->accountId))
            ->selectRaw("'card_statement_payment' as source_kind, payment.id as source_id, payment.payment_date as recognized_date, 'card_settlement' as classification, payment.financial_account_id as account_id, payment.amount_centavos as signed_amount_centavos, NULL as description, payment.credit_card_statement_id as related_statement_id");
    }
}
