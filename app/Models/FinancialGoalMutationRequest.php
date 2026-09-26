<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'idempotency_key', 'operation', 'request_fingerprint', 'financial_goal_id', 'response_status', 'response_body', 'completed_at'])]
class FinancialGoalMutationRequest extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<FinancialGoal, $this> */
    public function financialGoal(): BelongsTo
    {
        return $this->belongsTo(FinancialGoal::class);
    }
}
