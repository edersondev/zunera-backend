<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NotificationEvent extends Model
{
    protected $fillable = [
        'user_id',
        'event_key',
        'type',
        'category',
        'severity',
        'source_kind',
        'source_id',
        'business_context',
        'event_at',
        'read_at',
        'resolved_at',
        'visibility',
        'expired_at',
        'event_snapshot',
        'current_context',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'business_context' => 'array',
            'event_at' => 'datetime',
            'read_at' => 'datetime',
            'resolved_at' => 'datetime',
            'expired_at' => 'datetime',
            'event_snapshot' => 'array',
            'current_context' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
