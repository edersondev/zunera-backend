<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditCards\CreditCardCreditEventReason;
use Database\Factories\CreditCardCreditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'credit_card_id', 'credit_card_purchase_id', 'reason', 'amount_centavos', 'currency_code', 'event_date', 'notes'])]
class CreditCardCreditEvent extends Model
{
    /** @use HasFactory<CreditCardCreditEventFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'reason' => CreditCardCreditEventReason::class,
            'amount_centavos' => 'integer',
            'event_date' => 'date:Y-m-d',
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

    /** @return BelongsTo<CreditCardPurchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(CreditCardPurchase::class, 'credit_card_purchase_id');
    }

    /** @return HasMany<CreditCardCreditApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(CreditCardCreditApplication::class, 'credit_card_credit_event_id')->orderBy('id');
    }
}
