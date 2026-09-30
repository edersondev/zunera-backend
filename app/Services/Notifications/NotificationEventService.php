<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Data\Notifications\NotificationCandidate;
use App\Models\NotificationEvent;
use Illuminate\Support\Facades\DB;

final class NotificationEventService
{
    public function buildKey(NotificationCandidate $candidate): string
    {
        $identity = $candidate->identity;
        ksort($identity);

        return hash('sha256', json_encode([
            $candidate->type,
            $candidate->sourceKind,
            $candidate->sourceId,
            $identity,
        ], JSON_THROW_ON_ERROR));
    }

    public function qualify(NotificationCandidate $candidate, bool $enabled): NotificationEvent
    {
        return DB::transaction(function () use ($candidate, $enabled): NotificationEvent {
            $key = $this->buildKey($candidate);
            $now = now();
            $inserted = DB::table('notification_events')->insertOrIgnore([
                'user_id' => $candidate->userId,
                'event_key' => $key,
                'type' => $candidate->type,
                'category' => $candidate->category,
                'severity' => $candidate->severity,
                'source_kind' => $candidate->sourceKind,
                'source_id' => $candidate->sourceId,
                'business_context' => json_encode($candidate->identity, JSON_THROW_ON_ERROR),
                'event_at' => $candidate->eventAt,
                'read_at' => null,
                'resolved_at' => $candidate->actionable ? null : $now,
                'visibility' => $enabled ? 'visible' : 'suppressed',
                'expired_at' => null,
                'event_snapshot' => $enabled ? json_encode($candidate->snapshot, JSON_THROW_ON_ERROR) : null,
                'current_context' => $enabled ? json_encode($candidate->currentContext, JSON_THROW_ON_ERROR) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $event = NotificationEvent::query()
                ->where('user_id', $candidate->userId)
                ->where('event_key', $key)
                ->lockForUpdate()
                ->firstOrFail();

            if ($inserted === 0 && $event->visibility === 'visible' && $candidate->actionable && $enabled) {
                $event->severity = $candidate->severity;
                $event->current_context = $candidate->currentContext;
                $event->resolved_at = null;
                $event->save();
            }

            return $event;
        });
    }

    public function consume(NotificationCandidate $candidate): NotificationEvent
    {
        return $this->qualify($candidate, false);
    }

    public function resolve(NotificationCandidate $candidate): void
    {
        NotificationEvent::query()
            ->where('user_id', $candidate->userId)
            ->where('event_key', $this->buildKey($candidate))
            ->where('visibility', 'visible')
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'read_at' => now(), 'current_context' => null]);
    }
}
