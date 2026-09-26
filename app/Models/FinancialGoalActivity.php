<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['financial_goal_id', 'user_id', 'type', 'amount_centavos', 'financial_account_id_at_time', 'account_name_at_time', 'details', 'occurred_at', 'business_date'])]
class FinancialGoalActivity extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_centavos' => 'integer',
            'financial_account_id_at_time' => 'integer',
            'details' => 'array',
            'occurred_at' => 'datetime',
            'business_date' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<FinancialGoal, $this> */
    public function financialGoal(): BelongsTo
    {
        return $this->belongsTo(FinancialGoal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
