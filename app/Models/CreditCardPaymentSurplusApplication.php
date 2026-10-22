<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'credit_card_id', 'source_statement_id', 'destination_statement_id', 'amount_centavos', 'applied_at', 'superseded_at'])]
class CreditCardPaymentSurplusApplication extends Model
{
    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'amount_centavos' => 'integer',
            'applied_at' => 'date:Y-m-d',
            'superseded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CreditCard, $this> */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /** @return BelongsTo<CreditCardStatement, $this> */
    public function sourceStatement(): BelongsTo
    {
        return $this->belongsTo(CreditCardStatement::class, 'source_statement_id');
    }

    /** @return BelongsTo<CreditCardStatement, $this> */
    public function destinationStatement(): BelongsTo
    {
        return $this->belongsTo(CreditCardStatement::class, 'destination_statement_id');
    }

    /** @param Builder<CreditCardPaymentSurplusApplication> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('superseded_at');
    }
}
