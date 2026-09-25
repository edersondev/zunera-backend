<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\RecurrenceDestinationType;
use App\Enums\RecurringTransactions\RecurrenceFrequency;
use App\Enums\RecurringTransactions\RecurrencePausedReason;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionType;
use Database\Factories\RecurringTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'destination_type',
    'financial_account_id',
    'credit_card_id',
    'generation_mode',
    'category_id',
    'type',
    'amount_centavos',
    'currency_code',
    'description',
    'notes',
    'frequency',
    'start_date',
    'end_date',
    'state',
    'paused_reason',
    'eligibility_starts_on',
    'schedule_cursor',
    'ended_at',
])]
class RecurringTransaction extends Model
{
    /** @use HasFactory<RecurringTransactionFactory> */
    use HasFactory;

    public const int MIN_AMOUNT_CENTAVOS = 1;

    public const int MAX_AMOUNT_CENTAVOS = 99_999_999_999;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'frequency' => RecurrenceFrequency::class,
            'state' => RecurrenceState::class,
            'paused_reason' => RecurrencePausedReason::class,
            'destination_type' => RecurrenceDestinationType::class,
            'generation_mode' => CardGenerationMode::class,
            'amount_centavos' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'eligibility_starts_on' => 'date',
            'schedule_cursor' => 'date',
            'ended_at' => 'datetime',
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

    /** @return HasMany<Transaction, $this> */
    public function generatedOccurrences(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recurring_transaction_id');
    }

    /** @return HasMany<RecurringCardOccurrence, $this> */
    public function cardOccurrences(): HasMany
    {
        return $this->hasMany(RecurringCardOccurrence::class, 'recurring_transaction_id');
    }

    public function destinationType(): RecurrenceDestinationType
    {
        return $this->destination_type ?? RecurrenceDestinationType::FinancialAccount;
    }

    public function isCardDestination(): bool
    {
        return $this->destinationType()->isCard();
    }

    public function isAccountDestination(): bool
    {
        return $this->destinationType()->isAccount();
    }

    public function generationModeOrAutomatic(): ?CardGenerationMode
    {
        return $this->isCardDestination()
            ? ($this->generation_mode ?? CardGenerationMode::Automatic)
            : null;
    }

    /** @param Builder<RecurringTransaction> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('state', RecurrenceState::Active);
    }

    /** @param Builder<RecurringTransaction> $query */
    public function scopePaused(Builder $query): void
    {
        $query->where('state', RecurrenceState::Paused);
    }

    /** @param Builder<RecurringTransaction> $query */
    public function scopeEnded(Builder $query): void
    {
        $query->where('state', RecurrenceState::Ended);
    }

    public function isActive(): bool
    {
        return $this->state === RecurrenceState::Active;
    }

    public function isPaused(): bool
    {
        return $this->state === RecurrenceState::Paused;
    }

    public function isEnded(): bool
    {
        return $this->state === RecurrenceState::Ended;
    }

    public function endDateOrNull(): ?string
    {
        return $this->end_date?->toDateString();
    }
}
