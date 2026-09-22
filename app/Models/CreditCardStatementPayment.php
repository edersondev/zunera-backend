<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditCards\CreditCardPaymentStatus;
use Database\Factories\CreditCardStatementPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'credit_card_statement_id', 'credit_card_id', 'financial_account_id', 'amount_centavos', 'currency_code', 'payment_date', 'notes', 'status', 'removed_at'])]
class CreditCardStatementPayment extends Model
{
    /** @use HasFactory<CreditCardStatementPaymentFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => CreditCardPaymentStatus::class,
            'amount_centavos' => 'integer',
            'payment_date' => 'date:Y-m-d',
            'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CreditCardStatement, $this> */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(CreditCardStatement::class, 'credit_card_statement_id');
    }

    /** @return BelongsTo<CreditCard, $this> */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /** @return BelongsTo<FinancialAccount, $this> */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function countsTowardBalance(): bool
    {
        return $this->removed_at === null && $this->status === CreditCardPaymentStatus::Effective;
    }

    public function accountBalanceEffect(): int
    {
        return $this->countsTowardBalance() ? -$this->amount_centavos : 0;
    }

    /** @param Builder<CreditCardStatementPayment> $query */
    public function scopeEffective(Builder $query): void
    {
        $query->where('status', CreditCardPaymentStatus::Effective)->whereNull('removed_at');
    }
}
