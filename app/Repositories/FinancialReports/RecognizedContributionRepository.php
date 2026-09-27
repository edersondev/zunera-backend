<?php

declare(strict_types=1);

namespace App\Repositories\FinancialReports;

use App\Data\FinancialReports\ReportScope;
use App\Services\CreditCards\BillingCycleCalculator;
use App\Services\CreditCards\RecognizedCardExpenseProjection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Owner-scoped, virtual signed source rows shared by every report amount. */
final class RecognizedContributionRepository
{
    public function __construct(private readonly RecognizedCardExpenseProjection $cards) {}

    public function query(ReportScope $scope, string $whichPeriod = 'current'): Builder
    {
        $period = $scope->period($whichPeriod);
        $ordinary = $this->ordinary($scope, $period['from'], $period['to']);
        $principal = $this->cardPrincipal($scope, $period['from'], $period['to']);
        $adjustments = $this->cardAdjustments($scope, $period['from'], $period['to']);

        $union = $ordinary->unionAll($principal)->unionAll($adjustments);

        return DB::query()->fromSub($union, 'contribution')
            ->leftJoin('categories as category', 'category.id', '=', 'contribution.category_id')
            ->leftJoin('financial_accounts as account', 'account.id', '=', 'contribution.financial_account_id')
            ->select('contribution.*')
            ->selectRaw('category.name as category_name, category.classification as category_classification, category.status as category_status')
            ->selectRaw('account.name as account_name, account.account_type as account_type, account.status as account_status');
    }

    private function ordinary(ReportScope $scope, string $from, string $to): Builder
    {
        return DB::table('transactions as movement')
            ->where('movement.user_id', $scope->userId)
            ->where('movement.status', 'effective')
            ->whereNull('movement.removed_at')
            ->whereDate('movement.transaction_date', '>=', $from)
            ->whereDate('movement.transaction_date', '<=', $to)
            ->when($scope->accountId !== null, fn (Builder $query) => $query->where('movement.financial_account_id', $scope->accountId))
            ->when($scope->categoryId !== null, fn (Builder $query) => $query->where('movement.category_id', $scope->categoryId))
            ->when($scope->transactionType !== null, fn (Builder $query) => $query->where('movement.type', $scope->transactionType))
            ->selectRaw("'ordinary_transaction' as source_kind, movement.id as source_id, movement.transaction_date as recognized_date, movement.type as classification, movement.category_id, movement.financial_account_id, movement.amount_centavos as signed_amount_centavos, movement.description, NULL as related_purchase_id, NULL as related_statement_id, NULL as related_installment_id, NULL as related_credit_event_id");
    }

    private function cardPrincipal(ReportScope $scope, string $from, string $to): Builder
    {
        $query = DB::query()->fromSub($this->cards->installments($scope->userId, $from, $to), 'recognized')
            ->join('credit_card_purchases as purchase', 'purchase.id', '=', 'recognized.purchase_id')
            ->where('purchase.user_id', $scope->userId)
            ->when($scope->categoryId !== null, fn (Builder $builder) => $builder->where('recognized.category_id', $scope->categoryId))
            ->selectRaw("'card_installment' as source_kind, recognized.installment_id as source_id, recognized.closing_date as recognized_date, 'expense' as classification, recognized.category_id, NULL as financial_account_id, recognized.amount_centavos as signed_amount_centavos, purchase.description, recognized.purchase_id as related_purchase_id, recognized.statement_id as related_statement_id, recognized.installment_id as related_installment_id, NULL as related_credit_event_id");

        if ($scope->accountId !== null || $scope->transactionType === 'income') {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function cardAdjustments(ReportScope $scope, string $from, string $to): Builder
    {
        $installments = DB::table('credit_card_installments as installment')
            ->where('installment.user_id', $scope->userId)
            ->selectRaw('installment.id, installment.credit_card_purchase_id, installment.credit_card_statement_id, installment.amount_centavos')
            ->selectRaw('SUM(installment.amount_centavos) OVER (PARTITION BY installment.credit_card_purchase_id ORDER BY installment.sequence, installment.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as prefix_centavos');
        $events = DB::table('credit_card_credit_events as event')
            ->where('event.user_id', $scope->userId)
            ->selectRaw('event.id, event.credit_card_purchase_id, event.amount_centavos')
            ->selectRaw('SUM(event.amount_centavos) OVER (PARTITION BY event.credit_card_purchase_id ORDER BY event.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as prefix_centavos');

        $installmentStart = '(installment.prefix_centavos - installment.amount_centavos)';
        $eventStart = '(event.prefix_centavos - event.amount_centavos)';
        $end = 'CASE WHEN installment.prefix_centavos < event.prefix_centavos THEN installment.prefix_centavos ELSE event.prefix_centavos END';
        $start = "CASE WHEN {$installmentStart} > {$eventStart} THEN {$installmentStart} ELSE {$eventStart} END";
        $allocated = "CASE WHEN event.prefix_centavos <= {$installmentStart} OR installment.prefix_centavos <= {$eventStart} THEN 0 ELSE ({$end}) - ({$start}) END";

        $query = DB::query()->fromSub($installments, 'installment')
            ->joinSub($events, 'event', 'event.credit_card_purchase_id', '=', 'installment.credit_card_purchase_id')
            ->join('credit_card_statements as statement', 'statement.id', '=', 'installment.credit_card_statement_id')
            ->join('credit_card_purchases as purchase', 'purchase.id', '=', 'installment.credit_card_purchase_id')
            ->where('statement.user_id', $scope->userId)
            ->where('purchase.user_id', $scope->userId)
            ->whereDate('statement.closing_date', '>=', $from)
            ->whereDate('statement.closing_date', '<=', $to)
            ->whereDate('statement.closing_date', '<', app(BillingCycleCalculator::class)->businessToday()->toDateString())
            ->whereRaw("({$allocated}) > 0")
            ->when($scope->categoryId !== null, fn (Builder $builder) => $builder->where('purchase.category_id', $scope->categoryId))
            ->selectRaw("'card_credit_adjustment' as source_kind, event.id as source_id, statement.closing_date as recognized_date, 'expense' as classification, purchase.category_id, NULL as financial_account_id, -({$allocated}) as signed_amount_centavos, purchase.description, purchase.id as related_purchase_id, statement.id as related_statement_id, installment.id as related_installment_id, event.id as related_credit_event_id");

        if ($scope->accountId !== null || $scope->transactionType === 'income') {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }
}
