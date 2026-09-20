<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditCards\CreditCardStatementStatus;
use Database\Factories\CreditCardStatementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'credit_card_id',
    'period_from',
    'period_to',
    'closing_date',
    'due_date',
    'original_amount_centavos',
    'credit_adjustment_centavos',
    'paid_centavos',
    'card_credit_applied_centavos',
    'status',
    'finalized_at',
])]
class CreditCardStatement extends Model
{
    /** @use HasFactory<CreditCardStatementFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'closing_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'original_amount_centavos' => 'integer',
            'credit_adjustment_centavos' => 'integer',
            'paid_centavos' => 'integer',
            'card_credit_applied_centavos' => 'integer',
            'status' => CreditCardStatementStatus::class,
            'finalized_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CreditCard, $this> */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /** @return HasMany<CreditCardInstallment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(CreditCardInstallment::class, 'credit_card_statement_id')->orderBy('id');
    }

    /** @return HasMany<CreditCardStatementPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(CreditCardStatementPayment::class, 'credit_card_statement_id')->orderBy('id');
    }

    /** @return HasMany<CreditCardCreditApplication, $this> */
    public function creditApplications(): HasMany
    {
        return $this->hasMany(CreditCardCreditApplication::class, 'credit_card_statement_id')->orderBy('id');
    }

    public function netAmountCentavos(): int
    {
        return max(0, $this->original_amount_centavos - $this->credit_adjustment_centavos);
    }

    public function outstandingCentavos(): int
    {
        return max(0, $this->netAmountCentavos() - $this->paid_centavos - $this->card_credit_applied_centavos);
    }

    public function isOpen(): bool
    {
        return $this->status === CreditCardStatementStatus::Open;
    }
}
