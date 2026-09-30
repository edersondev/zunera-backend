<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Data\Notifications\NotificationCandidate;
use App\Data\Notifications\NotificationProjectionData;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Enums\Transactions\TransactionStatus;
use App\Models\NotificationEvent;
use App\Models\NotificationProjectionFact;
use App\Models\RecurringCardOccurrence;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

final class RecurringReviewProjector
{
    public function __construct(
        private readonly NotificationEventService $events,
        private readonly NotificationPreferenceService $preferences,
        private readonly NotificationProjectionFactService $facts,
    ) {}

    public function captureTransaction(Transaction $transaction): void
    {
        if (! $transaction->isGeneratedFromRecurrence()) {
            return;
        }

        $this->facts->capture(new NotificationProjectionData(
            userId: (int) $transaction->user_id,
            sourceKind: 'transaction',
            sourceId: (int) $transaction->id,
            qualifiedType: $this->needsTransactionReview($transaction) ? 'recurrence_review' : null,
            qualifiedAt: CarbonImmutable::now(),
        ));
    }

    public function captureCard(RecurringCardOccurrence $occurrence): void
    {
        $this->facts->capture(new NotificationProjectionData(
            userId: (int) $occurrence->user_id,
            sourceKind: 'recurring_card_occurrence',
            sourceId: (int) $occurrence->id,
            qualifiedType: $occurrence->state->isActionable() ? 'recurrence_review' : null,
            qualifiedAt: CarbonImmutable::now(),
            context: ['state' => $occurrence->state->value],
        ));
    }

    public function handleFact(NotificationProjectionFact $fact): void
    {
        if ($fact->source_kind === 'transaction') {
            $transaction = Transaction::query()->where('user_id', $fact->user_id)->find($fact->source_id);
            if ($transaction === null || ! $transaction->isGeneratedFromRecurrence()) {
                $this->resolveMissing($fact);

                return;
            }

            $current = $this->needsTransactionReview($transaction);
            $at = $fact->qualified_at === null ? CarbonImmutable::now() : CarbonImmutable::instance($fact->qualified_at);
            $candidate = $this->transactionCandidate($transaction, $at);
        } else {
            $occurrence = RecurringCardOccurrence::query()->where('user_id', $fact->user_id)->find($fact->source_id);
            if ($occurrence === null) {
                $this->resolveMissing($fact);

                return;
            }

            $current = $occurrence->state->isActionable();
            $at = $fact->qualified_at === null ? CarbonImmutable::now() : CarbonImmutable::instance($fact->qualified_at);
            $candidate = $this->cardCandidate($occurrence, $at);
        }

        if ($fact->qualified_type === 'recurrence_review' && $current) {
            $this->events->qualify($candidate, $this->preferences->enabledAt((int) $fact->user_id, 'recurring_transactions', $at));
        } elseif ($fact->qualified_type === 'recurrence_review') {
            $this->events->qualify($candidate, $this->preferences->enabledAt((int) $fact->user_id, 'recurring_transactions', $at));
            $this->events->resolve($candidate);
        } elseif ($current) {
            $this->events->qualify($candidate, $this->preferences->enabledAt((int) $fact->user_id, 'recurring_transactions', CarbonImmutable::now()));
        } else {
            $this->events->resolve($candidate);
        }
    }

    public function evaluateTransaction(int $userId, int $transactionId): void
    {
        $transaction = Transaction::query()->where('user_id', $userId)->find($transactionId);
        if ($transaction === null || ! $transaction->isGeneratedFromRecurrence()) {
            $this->resolveBySource($userId, 'transaction', $transactionId);

            return;
        }
        $candidate = $this->transactionCandidate($transaction, CarbonImmutable::now());
        if ($this->needsTransactionReview($transaction)) {
            $this->events->qualify($candidate, $this->preferences->enabledAt($userId, 'recurring_transactions', CarbonImmutable::now()));
        } else {
            $this->events->resolve($candidate);
        }
    }

    public function evaluateCard(int $userId, int $occurrenceId): void
    {
        $occurrence = RecurringCardOccurrence::query()->where('user_id', $userId)->find($occurrenceId);
        if ($occurrence === null) {
            $this->resolveBySource($userId, 'recurring_card_occurrence', $occurrenceId);

            return;
        }
        $candidate = $this->cardCandidate($occurrence, CarbonImmutable::now());
        if ($occurrence->state->isActionable()) {
            $this->events->qualify($candidate, $this->preferences->enabledAt($userId, 'recurring_transactions', CarbonImmutable::now()));
        } else {
            $this->events->resolve($candidate);
        }
    }

    private function needsTransactionReview(Transaction $transaction): bool
    {
        return $transaction->isGeneratedFromRecurrence()
            && $transaction->status === TransactionStatus::Pending
            && $transaction->removed_at === null;
    }

    private function transactionCandidate(Transaction $transaction, CarbonImmutable $at): NotificationCandidate
    {
        $description = $this->safe((string) $transaction->description);
        $date = $transaction->recurrence_scheduled_date?->format('d/m/Y') ?? '';
        $summary = [
            'pt-BR' => "Revise {$description} de {$date} na transação gerada.",
            'en' => "Review {$description} for {$date} in the generated transaction.",
        ];

        return new NotificationCandidate(
            userId: (int) $transaction->user_id,
            type: 'recurrence_review',
            category: 'recurring_transactions',
            severity: 'attention',
            sourceKind: 'transaction',
            sourceId: (int) $transaction->id,
            identity: ['review' => 'generated_occurrence'],
            eventAt: $at,
            snapshot: ['title' => ['pt-BR' => 'Recorrência para revisar', 'en' => 'Recurrence needs review'], 'summary' => $summary],
            currentContext: ['summary' => $summary],
        );
    }

    private function cardCandidate(RecurringCardOccurrence $occurrence, CarbonImmutable $at): NotificationCandidate
    {
        $date = $occurrence->scheduled_date->format('d/m/Y');
        $description = $this->safe((string) $occurrence->description_snapshot);
        $summary = match ($occurrence->state) {
            CardOccurrenceState::AwaitingOverLimit => [
                'pt-BR' => "{$description} de {$date} precisa de aprovação do limite antes de registrar a compra.",
                'en' => "{$description} for {$date} needs a limit decision before recording the purchase.",
            ],
            CardOccurrenceState::Failed => [
                'pt-BR' => "Não foi possível registrar {$description} de {$date}. Revise e tente novamente.",
                'en' => "Could not record {$description} for {$date}. Review and retry.",
            ],
            default => [
                'pt-BR' => "Confirme ou dispense {$description} de {$date} antes de registrar a compra.",
                'en' => "Confirm or dismiss {$description} for {$date} before recording the purchase.",
            ],
        };

        return new NotificationCandidate(
            userId: (int) $occurrence->user_id,
            type: 'recurrence_review',
            category: 'recurring_transactions',
            severity: 'attention',
            sourceKind: 'recurring_card_occurrence',
            sourceId: (int) $occurrence->id,
            identity: ['review' => 'generated_occurrence'],
            eventAt: $at,
            snapshot: ['title' => ['pt-BR' => 'Compra recorrente para revisar', 'en' => 'Recurring card purchase needs review'], 'summary' => $summary],
            currentContext: ['summary' => $summary, 'state' => $occurrence->state->value],
        );
    }

    private function safe(string $text): string
    {
        return mb_substr(trim(strip_tags($text)), 0, 80);
    }

    private function resolveMissing(NotificationProjectionFact $fact): void
    {
        $this->resolveBySource((int) $fact->user_id, (string) $fact->source_kind, (int) $fact->source_id);
    }

    private function resolveBySource(int $userId, string $kind, int $sourceId): void
    {
        NotificationEvent::query()->where('user_id', $userId)->where('source_kind', $kind)
            ->where('source_id', $sourceId)->whereNull('resolved_at')->where('visibility', 'visible')
            ->update(['resolved_at' => now(), 'read_at' => now(), 'current_context' => null]);
    }
}
