<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'credit_card_id', 'credit_card_purchase_id', 'credit_card_installment_id',
    'source_statement_id', 'destination_statement_id', 'mode', 'sequence',
    'source_closing_date', 'source_period_from', 'source_period_to', 'source_due_date',
    'destination_closing_date', 'destination_period_from', 'destination_period_to', 'destination_due_date',
    'operation_id', 'moved_at',
])]
class CreditCardPurchaseMove extends Model
{
    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'source_closing_date' => 'date:Y-m-d',
            'source_period_from' => 'date:Y-m-d',
            'source_period_to' => 'date:Y-m-d',
            'source_due_date' => 'date:Y-m-d',
            'destination_closing_date' => 'date:Y-m-d',
            'destination_period_from' => 'date:Y-m-d',
            'destination_period_to' => 'date:Y-m-d',
            'destination_due_date' => 'date:Y-m-d',
            'moved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CreditCardPurchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(CreditCardPurchase::class, 'credit_card_purchase_id');
    }

    /** @return BelongsTo<CreditCardInstallment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(CreditCardInstallment::class, 'credit_card_installment_id');
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
}
