<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditCards\CreditCardStatus;
use Database\Factories\CreditCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name', 'institution_name', 'last_four', 'color', 'icon', 'credit_limit_centavos', 'closing_day', 'due_day', 'status', 'archived_at'])]
class CreditCard extends Model
{
    /** @use HasFactory<CreditCardFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => CreditCardStatus::class,
            'credit_limit_centavos' => 'integer',
            'closing_day' => 'integer',
            'due_day' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CreditCardPurchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(CreditCardPurchase::class);
    }

    /** @return HasMany<CreditCardStatement, $this> */
    public function statements(): HasMany
    {
        return $this->hasMany(CreditCardStatement::class);
    }

    /** @return HasMany<CreditCardStatementPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(CreditCardStatementPayment::class);
    }

    /** @return HasMany<CreditCardCreditEvent, $this> */
    public function creditEvents(): HasMany
    {
        return $this->hasMany(CreditCardCreditEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === CreditCardStatus::Active;
    }

    /** @param Builder<CreditCard> $query */
    public function scopeOwnedBy(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }

    /** @param Builder<CreditCard> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', CreditCardStatus::Active);
    }

    /** @param Builder<CreditCard> $query */
    public function scopeArchived(Builder $query): void
    {
        $query->where('status', CreditCardStatus::Archived);
    }
}
