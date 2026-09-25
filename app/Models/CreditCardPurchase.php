<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CreditCardPurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'credit_card_id',
    'category_id',
    'description',
    'notes',
    'total_amount_centavos',
    'installment_count',
    'purchase_date',
    'currency_code',
    'card_name_snapshot',
    'category_name_snapshot',
    'category_status_snapshot',
    'recurring_card_occurrence_id',
])]
class CreditCardPurchase extends Model
{
    /** @use HasFactory<CreditCardPurchaseFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'total_amount_centavos' => 'integer',
            'installment_count' => 'integer',
            'purchase_date' => 'date:Y-m-d',
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

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<CreditCardInstallment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(CreditCardInstallment::class)->orderBy('sequence');
    }

    /** @return HasMany<CreditCardCreditEvent, $this> */
    public function creditEvents(): HasMany
    {
        return $this->hasMany(CreditCardCreditEvent::class)->orderBy('id');
    }

    /** @return BelongsTo<RecurringCardOccurrence, $this> */
    public function recurringCardOccurrence(): BelongsTo
    {
        return $this->belongsTo(RecurringCardOccurrence::class, 'recurring_card_occurrence_id');
    }
}
