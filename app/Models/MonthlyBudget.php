<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MonthlyBudgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'budget_year', 'budget_month'])]
class MonthlyBudget extends Model
{
    /** @use HasFactory<MonthlyBudgetFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['budget_year' => 'integer', 'budget_month' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<BudgetCategoryPlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(BudgetCategoryPlan::class);
    }
}
