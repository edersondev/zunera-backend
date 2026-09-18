<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialDashboard;

use App\Data\FinancialDashboard\DashboardPeriodData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     period: DashboardPeriodData,
 *     current_total_balance_centavos: int,
 *     realized_income_centavos: int,
 *     realized_expenses_centavos: int,
 *     financial_result_centavos: int,
 *     currency_code: string
 * } $resource
 */
final class DashboardSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = (string) $this->resource['currency_code'];

        return [
            'period' => DashboardPeriodResource::shape($this->resource['period']),
            'current_total_balance' => MoneyResource::shape((int) $this->resource['current_total_balance_centavos'], $currency),
            'realized_income' => MoneyResource::shape((int) $this->resource['realized_income_centavos'], $currency),
            'realized_expenses' => MoneyResource::shape((int) $this->resource['realized_expenses_centavos'], $currency),
            'financial_result' => MoneyResource::shape((int) $this->resource['financial_result_centavos'], $currency),
        ];
    }
}
