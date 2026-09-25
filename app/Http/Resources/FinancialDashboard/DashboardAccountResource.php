<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialDashboard;

use App\Models\FinancialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Account identity snapshot. Archived accounts remain readable so valid
 * historical labels explain themselves.
 *
 * @property FinancialAccount $resource
 */
final class DashboardAccountResource extends JsonResource
{
    /** @return array{id: int, name: string, type: string, status: string} */
    public function toArray(Request $request): array
    {
        return self::shape($this->resource);
    }

    /** @return array{id: int, name: string, type: string, status: string}|null */
    public static function shape(?FinancialAccount $account): ?array
    {
        if (! $account instanceof FinancialAccount) {
            return null;
        }

        return [
            'id' => (int) $account->id,
            'name' => (string) $account->name,
            'type' => $account->account_type->value,
            'status' => $account->status->value,
        ];
    }
}
