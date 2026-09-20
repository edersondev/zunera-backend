<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditCards\CreditCardCreditApplicationKind;
use Database\Factories\CreditCardCreditApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'credit_card_credit_event_id', 'credit_card_id', 'credit_card_installment_id', 'credit_card_statement_id', 'kind', 'amount_centavos', 'applied_at'])]
class CreditCardCreditApplication extends Model
{
    /** @use HasFactory<CreditCardCreditApplicationFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'kind' => CreditCardCreditApplicationKind::class,
            'amount_centavos' => 'integer',
            'applied_at' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<CreditCardCreditEvent, $this> */
    public function creditEvent(): BelongsTo
    {
        return $this->belongsTo(CreditCardCreditEvent::class, 'credit_card_credit_event_id');
    }

    /** @return BelongsTo<CreditCard, $this> */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /** @return BelongsTo<CreditCardInstallment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(CreditCardInstallment::class, 'credit_card_installment_id');
    }

    /** @return BelongsTo<CreditCardStatement, $this> */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(CreditCardStatement::class, 'credit_card_statement_id');
    }
}
