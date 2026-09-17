<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialDashboard;

use App\Data\FinancialDashboard\DashboardPeriodData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     period: DashboardPeriodData,
 *     total_expenses_centavos: int,
 *     currency_code: string,
 *     categories: list<array{category: array{id: int|string, name: string, classification: string, status: string}, total_centavos: int, share_percent: float, rank: int}>
 * } $resource
 */
final class ExpenseDistributionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = (string) $this->resource['currency_code'];

        $categories = array_map(
            fn (array $item): array => [
                'category' => DashboardCategoryResource::shape($item['category']),
                'total' => MoneyResource::shape((int) $item['total_centavos'], $currency),
                'share_percent' => $item['share_percent'],
                'rank' => (int) $item['rank'],
            ],
            $this->resource['categories'],
        );

        return [
            'period' => DashboardPeriodResource::shape($this->resource['period']),
            'total_expenses' => MoneyResource::shape((int) $this->resource['total_expenses_centavos'], $currency),
            'categories' => $categories,
        ];
    }
}
