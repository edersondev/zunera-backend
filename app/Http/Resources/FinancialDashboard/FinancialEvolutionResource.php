<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialDashboard;

use App\Data\FinancialDashboard\DashboardPeriodData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     period: DashboardPeriodData,
 *     interval: string,
 *     currency_code: string,
 *     intervals: list<array{from: string, to: string, label: string, is_partial: bool, income_centavos: int, expenses_centavos: int, result_centavos: int}>
 * } $resource
 */
final class FinancialEvolutionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = (string) $this->resource['currency_code'];

        $intervals = array_map(
            fn (array $interval): array => [
                'from' => $interval['from'],
                'to' => $interval['to'],
                'label' => $interval['label'],
                'is_partial' => (bool) $interval['is_partial'],
                'income' => MoneyResource::shape((int) $interval['income_centavos'], $currency),
                'expenses' => MoneyResource::shape((int) $interval['expenses_centavos'], $currency),
                'result' => MoneyResource::shape((int) $interval['result_centavos'], $currency),
            ],
            $this->resource['intervals'],
        );

        return [
            'period' => DashboardPeriodResource::shape($this->resource['period']),
            'interval' => (string) $this->resource['interval'],
            'intervals' => $intervals,
        ];
    }
}
