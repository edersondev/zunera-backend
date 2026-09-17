<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialDashboard;

use App\Models\FinancialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     current_total_balance_centavos: int,
 *     currency_code: string,
 *     accounts: list<array{account: FinancialAccount, current_balance_centavos: int, allocation_percent: float|null}>
 * } $resource
 */
final class DashboardAccountsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = (string) $this->resource['currency_code'];

        $accounts = array_map(
            fn (array $item): array => [
                'account' => DashboardAccountResource::shape($item['account']),
                'current_balance' => MoneyResource::shape((int) $item['current_balance_centavos'], $currency),
                'allocation_percent' => $item['allocation_percent'],
            ],
            $this->resource['accounts'],
        );

        return [
            'current_total_balance' => MoneyResource::shape((int) $this->resource['current_total_balance_centavos'], $currency),
            'accounts' => $accounts,
        ];
    }
}
