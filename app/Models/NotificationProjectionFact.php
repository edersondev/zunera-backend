<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NotificationProjectionFact extends Model
{
    protected $fillable = [
        'user_id',
        'source_kind',
        'source_id',
        'affected_year',
        'affected_month',
        'qualified_type',
        'qualified_at',
        'context',
        'available_at',
        'processed_at',
        'attempts',
        'last_error_code',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'affected_year' => 'integer',
            'affected_month' => 'integer',
            'qualified_at' => 'datetime',
            'context' => 'array',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
