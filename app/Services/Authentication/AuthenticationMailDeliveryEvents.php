<?php

declare(strict_types=1);

namespace App\Services\Authentication;

use App\Data\Authentication\AuthenticationMailDeliveryEventData;
use Illuminate\Support\Facades\DB;

final class AuthenticationMailDeliveryEvents
{
    public function record(AuthenticationMailDeliveryEventData $data): void
    {
        DB::table('auth_mail_delivery_events')->updateOrInsert(
            ['event_id' => $data->eventId],
            [
                'message_id' => $data->messageId,
                'status' => $data->status,
                'occurred_at' => $data->occurredAt,
                'received_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function deliveredCount(int $sampleSize): int
    {
        return DB::table('auth_mail_delivery_events')
            ->where('status', 'delivered')
            ->where('received_at', '>=', now()->subMinutes(5))
            ->latest('received_at')
            ->limit($sampleSize)
            ->count();
    }
}
