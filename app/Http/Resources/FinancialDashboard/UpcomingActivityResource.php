<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialDashboard;

use App\Models\Category;
use App\Models\CreditCard;
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
                'credit_card' => isset($item['credit_card']) && $item['credit_card'] instanceof CreditCard
                    ? [
                        'id' => (int) $item['credit_card']->id,
                        'name' => (string) $item['credit_card']->name,
                        'institution_name' => $item['credit_card']->institution_name,
                        'last_four' => $item['credit_card']->last_four,
                        'status' => $item['credit_card']->status->value,
                    ]
                    : null,
                'category' => DashboardCategoryResource::shape($item['category']),
                'description' => $item['description'],
                'destination_type' => $item['destination_type'] ?? 'financial_account',
                'recurring_transaction_id' => $item['recurring_transaction_id'] ?? null,
                'occurrence_id' => $item['occurrence_id'] ?? null,
                'state' => $item['state'] ?? 'expected',
            ],
            $this->resource['items'],
        );
    }
}
