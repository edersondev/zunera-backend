<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialReports\ReportContributionRequest;
use App\Http\Requests\FinancialReports\ReportScopeRequest;
use App\Http\Resources\FinancialReports\FinancialReportContributionResource;
use App\Http\Resources\FinancialReports\FinancialReportResource;
use App\Services\FinancialReports\FinancialReportContributionService;
use App\Services\FinancialReports\FinancialReportService;

final class FinancialReportController extends Controller
{
    public function show(ReportScopeRequest $request, FinancialReportService $reports): FinancialReportResource
    {
        return new FinancialReportResource($reports->overview($request->toScope()));
    }

    public function contributions(
        ReportContributionRequest $request,
        FinancialReportContributionService $contributions,
    ): FinancialReportContributionResource {
        $scope = $request->toScope();
        $data = $request->validated();

        return new FinancialReportContributionResource($contributions->page(
            $scope,
            (string) $data['metric'],
            isset($data['metric_id']) ? (int) $data['metric_id'] : null,
            (string) ($data['which_period'] ?? 'current'),
            $data['cursor'] ?? null,
            isset($data['limit']) ? (int) $data['limit'] : 50,
        ));
    }
}
