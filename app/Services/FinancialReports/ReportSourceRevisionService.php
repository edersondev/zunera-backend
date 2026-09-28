<?php

declare(strict_types=1);

namespace App\Services\FinancialReports;

use App\Data\FinancialReports\ReportScope;
use App\Repositories\FinancialReports\AccountMovementRepository;
use App\Repositories\FinancialReports\RecognizedContributionRepository;
use HashContext;
use Illuminate\Support\Facades\DB;

/** Opaque fingerprint of source rows and labels visible to one report scope. */
final class ReportSourceRevisionService
{
    public function __construct(
        private readonly RecognizedContributionRepository $contributions,
        private readonly AccountMovementRepository $movements,
    ) {}

    public function forScope(ReportScope $scope): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, json_encode([$scope->userId, $scope->toArray()], JSON_THROW_ON_ERROR));

        foreach (['current', 'previous'] as $whichPeriod) {
            foreach ($this->contributions->query($scope, $whichPeriod)
                ->orderBy('contribution.recognized_date')
                ->orderBy('contribution.source_kind')
                ->orderBy('contribution.source_id')
                ->orderBy('contribution.related_installment_id')
                ->cursor() as $row) {
                $this->includeRow($hash, 'contribution', $whichPeriod, $row);
            }

            foreach ($this->movements->query($scope, $whichPeriod)
                ->orderBy('movement.recognized_date')
                ->orderBy('movement.source_kind')
                ->orderBy('movement.source_id')
                ->orderBy('movement.classification')
                ->orderBy('movement.account_id')
                ->cursor() as $row) {
                $this->includeRow($hash, 'movement', $whichPeriod, $row);
            }
        }

        if ($scope->accountId !== null) {
            $account = DB::table('financial_accounts')
                ->where('user_id', $scope->userId)
                ->where('id', $scope->accountId)
                ->first(['id', 'name', 'account_type', 'status']);
            $this->includeRow($hash, 'selected_account', 'current', $account);
        }

        if ($scope->categoryId !== null) {
            $category = DB::table('categories')
                ->where('user_id', $scope->userId)
                ->where('id', $scope->categoryId)
                ->first(['id', 'name', 'classification', 'status']);
            $this->includeRow($hash, 'selected_category', 'current', $category);
        }

        return hash_final($hash);
    }

    private function includeRow(HashContext $hash, string $kind, string $period, ?object $row): void
    {
        $fields = $row === null ? null : (array) $row;
        if ($fields !== null) {
            ksort($fields);
        }
        hash_update($hash, json_encode([$kind, $period, $fields], JSON_THROW_ON_ERROR)."\n");
    }
}
