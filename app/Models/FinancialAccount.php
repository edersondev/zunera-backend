<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinancialAccounts\AccountStatus;
use App\Enums\FinancialAccounts\AccountType;
use App\Services\FinancialAccounts\FinancialAccountNameNormalizer;
use Database\Factories\FinancialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'name',
    'normalized_name',
    'account_type',
    'institution_name',
    'color',
    'icon',
    'initial_balance_centavos',
    'current_balance_centavos',
    'currency_code',
    'status',
    'archived_at',
    'has_financial_movements',
])]
class FinancialAccount extends Model
{
    /** @use HasFactory<FinancialAccountFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (FinancialAccount $account): void {
            if ($account->name !== null) {
                $account->normalized_name = FinancialAccountNameNormalizer::normalize((string) $account->name);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'status' => AccountStatus::class,
            'initial_balance_centavos' => 'integer',
            'current_balance_centavos' => 'integer',
            'archived_at' => 'datetime',
            'has_financial_movements' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
