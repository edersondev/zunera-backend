<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CreditCardMutationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'idempotency_key',
    'operation',
    'request_fingerprint',
    'target_type',
    'target_id',
    'response_status',
    'response_body',
    'completed_at',
])]
class CreditCardMutationRequest extends Model
{
    /** @use HasFactory<CreditCardMutationRequestFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_status' => 'integer',
            'target_id' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
