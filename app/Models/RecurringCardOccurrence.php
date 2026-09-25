<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RecurringCardOccurrence extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'recurring_transaction_id',
        'scheduled_date',
        'generation_mode_snapshot',
        'scheduled_amount_centavos',
        'description_snapshot',
        'notes_snapshot',
        'category_id_original',
        'credit_card_id_original',
        'card_identity_snapshot',
        'category_name_snapshot',
        'state',
        'actual_amount_centavos',
        'actual_purchase_date',
        'credit_card_id_override',
        'category_id_override',
        'action_claim_key',
        'action_choice_version',
        'action_claimed_at',
        'failure_code',
        'last_attempt_at',
        'recorded_at',
        'dismissed_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'generation_mode_snapshot' => CardGenerationMode::class,
            'scheduled_amount_centavos' => 'integer',
            'card_identity_snapshot' => 'array',
            'state' => CardOccurrenceState::class,
            'actual_amount_centavos' => 'integer',
            'actual_purchase_date' => 'date',
            'action_choice_version' => 'integer',
            'action_claimed_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'recorded_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<RecurringTransaction, $this> */
    public function recurringTransaction(): BelongsTo
    {
        return $this->belongsTo(RecurringTransaction::class, 'recurring_transaction_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function originalCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id_original');
    }

    /** @return BelongsTo<CreditCard, $this> */
    public function originalCreditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class, 'credit_card_id_original');
    }

    /** @return BelongsTo<CreditCard, $this> */
    public function overrideCreditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class, 'credit_card_id_override');
    }

    /** @return BelongsTo<Category, $this> */
    public function overrideCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id_override');
    }

    /** @return HasOne<CreditCardPurchase, $this> */
    public function purchase(): HasOne
    {
        return $this->hasOne(CreditCardPurchase::class, 'recurring_card_occurrence_id');
    }
}
