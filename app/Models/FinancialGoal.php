<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name', 'target_centavos', 'target_date', 'financial_account_id', 'account_name_snapshot', 'description', 'status', 'completed_at', 'archived_at'])]
class FinancialGoal extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_centavos' => 'integer',
            'target_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<FinancialAccount, $this> */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    /** @return HasMany<FinancialGoalActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(FinancialGoalActivity::class);
    }
}
