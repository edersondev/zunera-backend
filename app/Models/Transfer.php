<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Transfers\TransferStatus;
use App\Services\Transfers\TransferTextNormalizer;
use Database\Factories\TransferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'source_financial_account_id',
    'destination_financial_account_id',
    'status',
    'description',
    'notes',
    'amount_centavos',
    'currency_code',
    'transfer_date',
    'search_text',
    'removed_at',
])]
class Transfer extends Model
{
    /** @use HasFactory<TransferFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Transfer $transfer): void {
            $transfer->search_text = TransferTextNormalizer::normalize($transfer->description, $transfer->notes);
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'amount_centavos' => 'integer',
            'transfer_date' => 'date',
            'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<FinancialAccount, $this> */
    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'source_financial_account_id');
    }

    /** @return BelongsTo<FinancialAccount, $this> */
    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'destination_financial_account_id');
    }

    /** @param Builder<Transfer> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /** @param Builder<Transfer> $query */
    public function scopeRemoved(Builder $query): void
    {
        $query->whereNotNull('removed_at');
    }

    /** @param Builder<Transfer> $query */
    public function scopeEffective(Builder $query): void
    {
        $query->where('status', TransferStatus::Effective);
    }

    /** @param Builder<Transfer> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', TransferStatus::Pending);
    }

    /** @param Builder<Transfer> $query */
    public function scopeCounting(Builder $query): void
    {
        $query->active()->effective();
    }

    public function countsTowardBalance(): bool
    {
        return $this->removed_at === null && $this->status === TransferStatus::Effective;
    }

    public function sourceEffect(): int
    {
        return $this->countsTowardBalance() ? -1 * $this->amount_centavos : 0;
    }

    public function destinationEffect(): int
    {
        return $this->countsTowardBalance() ? $this->amount_centavos : 0;
    }
}
