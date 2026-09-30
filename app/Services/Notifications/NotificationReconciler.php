<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationEvent;
use App\Models\NotificationProjectionFact;
use Illuminate\Support\Facades\DB;
use Throwable;

final class NotificationReconciler
{
    public function expireResolved(int $limit = 500): int
    {
        $now = now();
        $cutoff = $now->copy()->subDays(90);
        $expired = 0;
        $candidates = NotificationEvent::query()
            ->where('visibility', 'visible')
            ->whereNotNull('resolved_at')
            ->where('created_at', '<=', $cutoff)
            ->where('resolved_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(max(1, min($limit, 1000)))
            ->get();

        foreach ($candidates as $event) {
            $retentionStart = $event->resolved_at->greaterThan($event->created_at)
                ? $event->resolved_at : $event->created_at;
            if ($retentionStart->copy()->addDays(90)->greaterThan($now)) {
                continue;
            }
            $event->forceFill([
                'visibility' => 'expired',
                'expired_at' => $now,
                'event_snapshot' => null,
                'current_context' => null,
            ])->save();
            $expired++;
        }

        return $expired;
    }

    /**
     * @param  callable(NotificationProjectionFact): void  $handler
     * @return array{processed: int, failed: int}
     */
    public function drain(callable $handler, int $limit = 100, ?int $userId = null): array
    {
        $ids = NotificationProjectionFact::query()
            ->whereNull('processed_at')
            ->where('available_at', '<=', now())
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->pluck('id');
        $processed = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $didProcess = DB::transaction(function () use ($id, $handler): bool {
                    $fact = NotificationProjectionFact::query()->whereKey($id)->lockForUpdate()->first();
                    if ($fact === null || $fact->processed_at !== null || $fact->available_at->isFuture()) {
                        return false;
                    }
                    $handler($fact);
                    $fact->processed_at = now();
                    $fact->last_error_code = null;
                    $fact->save();

                    return true;
                }, 3);
                $processed += (int) $didProcess;
            } catch (Throwable $exception) {
                $failed++;
                DB::transaction(function () use ($id, $exception): void {
                    $fact = NotificationProjectionFact::query()->whereKey($id)->lockForUpdate()->first();
                    if ($fact === null || $fact->processed_at !== null) {
                        return;
                    }
                    $fact->attempts++;
                    $fact->available_at = now()->addSeconds(min(300, 2 ** min($fact->attempts, 8)));
                    $fact->last_error_code = class_basename($exception);
                    $fact->save();
                }, 3);
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }
}
