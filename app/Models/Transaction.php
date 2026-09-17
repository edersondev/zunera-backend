<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Transactions\TransactionStatus;
use App\Enums\Transactions\TransactionType;
use App\Services\Transactions\TransactionTextNormalizer;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'financial_account_id', 'category_id', 'type', 'status', 'description', 'notes', 'amount_centavos', 'currency_code', 'transaction_date', 'search_text', 'removed_at', 'recurring_transaction_id', 'recurrence_scheduled_date'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Transaction $transaction): void {
            $transaction->search_text = TransactionTextNormalizer::normalize($transaction->description, $transaction->notes);
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount_centavos' => 'integer',
            'transaction_date' => 'date',
            'recurrence_scheduled_date' => 'date',
            'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<FinancialAccount, $this> */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<RecurringTransaction, $this> */
    public function recurringTransaction(): BelongsTo
    {
        return $this->belongsTo(RecurringTransaction::class, 'recurring_transaction_id');
    }

    public function isGeneratedFromRecurrence(): bool
    {
        return $this->recurring_transaction_id !== null && $this->recurrence_scheduled_date !== null;
    }

    /** @param Builder<Transaction> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /** @param Builder<Transaction> $query */
    public function scopeRemoved(Builder $query): void
    {
        $query->whereNotNull('removed_at');
    }

    /** @param Builder<Transaction> $query */
    public function scopeEffective(Builder $query): void
    {
        $query->where('status', TransactionStatus::Effective);
    }

    /** @param Builder<Transaction> $query */
    public function scopeCounting(Builder $query): void
    {
        $query->active()->effective();
    }

    public function countsTowardBalance(): bool
    {
        return $this->removed_at === null && $this->status === TransactionStatus::Effective;
    }

    public function balanceEffect(): int
    {
        return $this->countsTowardBalance() ? $this->type->balanceEffect($this->amount_centavos) : 0;
    }
}
