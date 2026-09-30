<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class NotificationCenterService
{
    public function __construct(
        private readonly NotificationReconciler $reconciler,
        private readonly NotificationFactDispatcher $dispatcher,
    ) {}

    /** @return array{items: Collection<int, NotificationEvent>, next_cursor: ?string} */
    public function listing(int $userId, string $view, int $limit, ?string $cursor): array
    {
        $this->refreshOwner($userId);
        $query = $this->visible($userId);
        $this->applyView($query, $view);
        if ($cursor !== null) {
            [$createdAt, $id] = $this->decodeCursor($cursor, $userId, $view);
            $query->where(static function (Builder $query) use ($createdAt, $id): void {
                $query->where('created_at', '<', $createdAt)
                    ->orWhere(static function (Builder $sameTime) use ($createdAt, $id): void {
                        $sameTime->where('created_at', $createdAt)->where('id', '<', $id);
                    });
            });
        }

        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->values();
        $last = $items->last();

        return [
            'items' => $items,
            'next_cursor' => $hasMore && $last !== null
                ? $this->encodeCursor($last->getRawOriginal('created_at'), (int) $last->id, $userId, $view)
                : null,
        ];
    }

    /** @return array{unread_count: int, requires_action_count: int} */
    public function summary(int $userId): array
    {
        $this->refreshOwner($userId);

        return $this->summaryWithoutRefresh($userId);
    }

    /** @return array{changed_count: int, summary: array{unread_count: int, requires_action_count: int}} */
    public function readAll(int $userId): array
    {
        $changed = $this->visible($userId)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return ['changed_count' => $changed, 'summary' => $this->summaryWithoutRefresh($userId)];
    }

    public function read(int $userId, int $eventId): NotificationEvent
    {
        $event = $this->visible($userId)->whereKey($eventId)->first();
        if ($event === null) {
            throw new NotFoundHttpException('Notification not found.');
        }
        if ($event->read_at === null) {
            $event->read_at = now();
            $event->save();
        }

        return $event;
    }

    private function refreshOwner(int $userId): void
    {
        $this->reconciler->drain($this->dispatcher->handle(...), 50, $userId);
    }

    /** @return array{unread_count: int, requires_action_count: int} */
    private function summaryWithoutRefresh(int $userId): array
    {
        $unread = $this->visible($userId)->whereNull('read_at')->count();
        $action = $this->visible($userId)->whereNull('resolved_at')->whereRaw($this->availableSourceSql())->count();

        return ['unread_count' => $unread, 'requires_action_count' => $action];
    }

    /** @return Builder<NotificationEvent> */
    private function visible(int $userId): Builder
    {
        $cutoff = now()->subDays(90);

        return NotificationEvent::query()
            ->where('user_id', $userId)
            ->where('visibility', 'visible')
            ->where(static function (Builder $query) use ($cutoff): void {
                $query->whereNull('resolved_at')
                    ->orWhere('created_at', '>', $cutoff)
                    ->orWhere('resolved_at', '>', $cutoff);
            })
            ->whereRaw($this->authorizedSourceSql());
    }

    /** @param Builder<NotificationEvent> $query */
    private function applyView(Builder $query, string $view): void
    {
        if ($view === 'unread') {
            $query->whereNull('read_at');
        } elseif ($view === 'requires_action') {
            $query->whereNull('resolved_at')->whereRaw($this->availableSourceSql());
        }
    }

    private function authorizedSourceSql(): string
    {
        $sources = [
            'credit_card_statement' => ['credit_card_statements', 'user_id'],
            'transaction' => ['transactions', 'user_id'],
            'recurring_card_occurrence' => ['recurring_card_occurrences', 'user_id'],
            'goal' => ['financial_goals', 'user_id'],
        ];
        $terms = [];
        foreach ($sources as $kind => [$table, $owner]) {
            $terms[] = "(notification_events.source_kind = '{$kind}' AND (NOT EXISTS (SELECT 1 FROM {$table} src WHERE src.id = notification_events.source_id) OR EXISTS (SELECT 1 FROM {$table} src WHERE src.id = notification_events.source_id AND src.{$owner} = notification_events.user_id)))";
        }
        $terms[] = "(notification_events.source_kind = 'budget_plan' AND (NOT EXISTS (SELECT 1 FROM budget_category_plans src WHERE src.id = notification_events.source_id) OR EXISTS (SELECT 1 FROM budget_category_plans src JOIN monthly_budgets b ON b.id = src.monthly_budget_id WHERE src.id = notification_events.source_id AND b.user_id = notification_events.user_id)))";

        return '('.implode(' OR ', $terms).')';
    }

    private function availableSourceSql(): string
    {
        return "((notification_events.source_kind = 'credit_card_statement' AND EXISTS (SELECT 1 FROM credit_card_statements src WHERE src.id = notification_events.source_id AND src.user_id = notification_events.user_id))"
            ." OR (notification_events.source_kind = 'transaction' AND EXISTS (SELECT 1 FROM transactions src WHERE src.id = notification_events.source_id AND src.user_id = notification_events.user_id AND src.removed_at IS NULL))"
            ." OR (notification_events.source_kind = 'recurring_card_occurrence' AND EXISTS (SELECT 1 FROM recurring_card_occurrences src WHERE src.id = notification_events.source_id AND src.user_id = notification_events.user_id))"
            ." OR (notification_events.source_kind = 'budget_plan' AND EXISTS (SELECT 1 FROM budget_category_plans src JOIN monthly_budgets b ON b.id = src.monthly_budget_id WHERE src.id = notification_events.source_id AND b.user_id = notification_events.user_id))"
            ." OR (notification_events.source_kind = 'goal' AND EXISTS (SELECT 1 FROM financial_goals src WHERE src.id = notification_events.source_id AND src.user_id = notification_events.user_id)))";
    }

    private function encodeCursor(string $createdAt, int $id, int $userId, string $view): string
    {
        $data = rtrim(strtr(base64_encode(json_encode([$createdAt, $id, $userId, $view], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $data, (string) config('app.key'));

        return $data.'.'.$signature;
    }

    /** @return array{string, int} */
    private function decodeCursor(string $cursor, int $userId, string $view): array
    {
        $parts = explode('.', $cursor, 2);
        if (count($parts) !== 2 || ! hash_equals(hash_hmac('sha256', $parts[0], (string) config('app.key')), $parts[1])) {
            throw ValidationException::withMessages(['cursor' => ['Invalid notification cursor.']]);
        }
        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $data = $decoded === false ? null : json_decode($decoded, true);
        if (! is_array($data) || count($data) !== 4 || ! is_string($data[0]) || ! is_int($data[1]) || $data[2] !== $userId || $data[3] !== $view) {
            throw ValidationException::withMessages(['cursor' => ['Invalid notification cursor.']]);
        }

        return [$data[0], $data[1]];
    }
}
