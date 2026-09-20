<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CreditCardInstallmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'credit_card_purchase_id', 'credit_card_id', 'credit_card_statement_id', 'sequence', 'amount_centavos', 'credit_adjustment_centavos', 'recognition_date'])]
class CreditCardInstallment extends Model
{
    /** @use HasFactory<CreditCardInstallmentFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'amount_centavos' => 'integer',
            'credit_adjustment_centavos' => 'integer',
            'recognition_date' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<CreditCardPurchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(CreditCardPurchase::class, 'credit_card_purchase_id');
    }

    /** @return BelongsTo<CreditCard, $this> */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /** @return BelongsTo<CreditCardStatement, $this> */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(CreditCardStatement::class, 'credit_card_statement_id');
    }

    public function netAmountCentavos(): int
    {
        return max(0, $this->amount_centavos - $this->credit_adjustment_centavos);
    }
}
