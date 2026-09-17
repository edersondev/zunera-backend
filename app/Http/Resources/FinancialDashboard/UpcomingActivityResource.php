<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialDashboard;

use App\Models\Category;
use App\Models\FinancialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     from: string,
 *     to: string,
 *     items: list<array{source_kind: string, expected_date: string, type: string, amount_centavos: int, currency_code: string, account: FinancialAccount, category: Category, description: string}>
 * } $resource
 */
final class UpcomingActivityResource extends JsonResource
{
    /** @return list<array<string, mixed>> */
    public function toArray(Request $request): array
    {
        return array_map(
            fn (array $item): array => [
                'source_kind' => $item['source_kind'],
                'expected_date' => $item['expected_date'],
                'type' => $item['type'],
                'amount' => MoneyResource::shape((int) $item['amount_centavos'], (string) $item['currency_code']),
                'account' => DashboardAccountResource::shape($item['account']),
                'category' => DashboardCategoryResource::shape($item['category']),
                'description' => $item['description'],
                'state' => 'expected',
            ],
            $this->resource['items'],
        );
    }
}
