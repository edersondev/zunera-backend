<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialGoals;

use App\Models\FinancialGoalActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FinancialGoalActivity */
final class FinancialGoalActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'goal_id' => (int) $this->financial_goal_id,
            'type' => $this->type,
            'amount_centavos' => $this->amount_centavos,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'business_date' => $this->business_date->toDateString(),
            'account_at_time' => $this->financial_account_id_at_time === null ? null : [
                'id' => (int) $this->financial_account_id_at_time,
                'name' => $this->account_name_at_time,
            ],
            'details' => $this->details ?? new \stdClass,
        ];
    }
}
