<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'idempotency_key',
    'operation',
    'request_fingerprint',
    'recurring_transaction_id',
    'response_status',
    'response_body',
    'completed_at',
])]
class RecurringMutationRequest extends Model
{
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_status' => 'integer',
            'completed_at' => 'datetime',
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
        return $this->belongsTo(RecurringTransaction::class);
    }
}
