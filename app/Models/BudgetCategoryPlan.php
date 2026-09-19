<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Categories\CategoryClassification;
use App\Enums\Categories\CategoryOrigin;
use Database\Factories\BudgetCategoryPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['monthly_budget_id', 'category_id', 'planned_amount_centavos', 'currency_code', 'category_name_snapshot', 'category_classification_snapshot', 'category_origin_snapshot', 'category_color_snapshot', 'category_icon_snapshot'])]
class BudgetCategoryPlan extends Model
{
    /** @use HasFactory<BudgetCategoryPlanFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // The first plan association locks the category classification as expense,
        // even before any financial transaction exists. Removing a plan never
        // unlocks it, so this stays a one-way marker.
        static::created(function (BudgetCategoryPlan $plan): void {
            Category::query()
                ->whereKey($plan->category_id)
                ->where('has_budget_plans', false)
                ->update(['has_budget_plans' => true]);
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'planned_amount_centavos' => 'integer',
            'category_classification_snapshot' => CategoryClassification::class,
            'category_origin_snapshot' => CategoryOrigin::class,
        ];
    }

    /** @return BelongsTo<MonthlyBudget, $this> */
    public function monthlyBudget(): BelongsTo
    {
        return $this->belongsTo(MonthlyBudget::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
