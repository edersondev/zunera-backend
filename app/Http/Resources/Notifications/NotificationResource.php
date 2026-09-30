<?php

declare(strict_types=1);

namespace App\Http\Resources\Notifications;

use App\Services\Notifications\NotificationSourceResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;

final class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $source = app(NotificationSourceResolver::class)->inspect($this->resource);
        $locale = App::getLocale() === 'en' ? 'en' : 'pt-BR';
        $snapshot = $this->event_snapshot ?? [];
        $title = $this->copy($snapshot['title'] ?? null, $locale, $this->type, 200);
        $current = $this->current_context ?? [];
        $summaryValue = $this->resolved_at === null && $source['source_available']
            ? ($current['summary'] ?? $snapshot['summary'] ?? null)
            : ($snapshot['summary'] ?? null);
        $summary = $this->copy($summaryValue, $locale, '', 500);

        return [
            'id' => (int) $this->id,
            'type' => $this->type,
            'category' => $this->category,
            'severity' => $this->severity,
            'title' => $title,
            'summary' => $summary,
            'origin' => match ($this->source_kind) {
                'credit_card_statement' => 'credit_cards',
                'transaction' => 'recurring_transactions',
                'recurring_card_occurrence' => 'recurring_card_purchases',
                'budget_plan' => 'budgets',
                'goal' => 'financial_goals',
            },
            'event_at' => $this->event_at->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'requires_action' => $this->resolved_at === null && $source['source_available'] && $this->type !== 'goal_reached',
            'source_available' => $source['source_available'],
            'destination' => $source['destination'],
        ];
    }

    private function copy(mixed $value, string $locale, string $fallback, int $limit): string
    {
        $text = is_array($value) ? ($value[$locale] ?? $value['pt-BR'] ?? $fallback) : $value;
        $text = is_string($text) ? $text : $fallback;
        $text = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $text) ?? '';

        return mb_substr(trim(strip_tags($text)), 0, $limit);
    }
}
