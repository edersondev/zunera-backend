<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Categories\CategoryClassification;
use App\Enums\Categories\CategoryOrigin;
use App\Enums\Categories\CategoryStatus;
use App\Services\Categories\CategoryNameNormalizer;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'origin', 'name', 'normalized_name', 'classification', 'color', 'icon', 'status', 'archived_at', 'has_financial_transactions'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Category $category): void {
            if ($category->name !== null) {
                $category->normalized_name = CategoryNameNormalizer::normalize((string) $category->name);
            }
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['origin' => CategoryOrigin::class, 'classification' => CategoryClassification::class, 'status' => CategoryStatus::class, 'archived_at' => 'datetime', 'has_financial_transactions' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
