<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Data\Notifications\NotificationCandidate;
use App\Data\Notifications\NotificationProjectionData;
use App\Enums\CreditCards\CreditCardStatementStatus;
use App\Models\CreditCardStatement;
use App\Models\NotificationEvent;
use App\Models\NotificationProjectionFact;
use App\Services\CreditCards\CreditCardObligationReconciler;
use App\Services\RecurringTransactions\RecurringDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class StatementNotificationProjector
{
    private const TYPES = ['statement_approaching', 'statement_due_today', 'statement_overdue'];

    public function __construct(
        private readonly CreditCardObligationReconciler $obligations,
        private readonly NotificationEventService $events,
        private readonly NotificationPreferenceService $preferences,
        private readonly NotificationProjectionFactService $facts,
    ) {}

    public function capture(CreditCardStatement $statement): void
    {
        $now = CarbonImmutable::now();
        $this->facts->capture(new NotificationProjectionData(
            userId: (int) $statement->user_id,
            sourceKind: 'credit_card_statement',
            sourceId: (int) $statement->id,
            qualifiedType: $this->stage($statement, $this->businessToday()),
            qualifiedAt: $now,
        ));
    }

    public function captureMissing(int $userId, int $statementId): void
    {
        $this->facts->capture(new NotificationProjectionData(
            userId: $userId,
            sourceKind: 'credit_card_statement',
            sourceId: $statementId,
        ));
    }

    public function evaluate(int $userId, int $statementId, ?CarbonImmutable $qualifiedAt = null): void
    {
        $statement = CreditCardStatement::query()->with('creditCard')->where('user_id', $userId)->find($statementId);
        if ($statement === null) {
            $this->resolveMissing($userId, $statementId);

            return;
        }

        $now = $qualifiedAt ?? CarbonImmutable::now();
        $stage = $this->stage($statement, $this->businessToday());
        foreach (self::TYPES as $type) {
            $candidate = $this->candidate($statement, $type, $now);
            if ($type === $stage) {
                $this->events->qualify($candidate, $this->preferences->enabledAt($userId, 'credit_cards', $now));
            } else {
                $this->events->resolve($candidate);
            }
        }
    }

    public function scanDateCandidates(int $limit = 5000): int
    {
        $today = $this->businessToday();
        $cursor = (int) Cache::get('notifications.statement_scan_cursor', 0);
        $statements = CreditCardStatement::query()
            ->where('id', '>', $cursor)
            ->whereDate('closing_date', '<', $today->toDateString())
            ->whereDate('due_date', '<=', $today->addDays(3)->toDateString())
            ->orderBy('id')
            ->limit(max(1, min($limit, 5000)))
            ->get();

        foreach ($statements as $statement) {
            $stage = $this->stage($statement, $today);
            $this->evaluate((int) $statement->user_id, (int) $statement->id, $this->boundaryFor($statement, $stage));
        }

        Cache::put('notifications.statement_scan_cursor', $statements->count() === $limit ? (int) $statements->last()->id : 0, now()->addDay());

        return $statements->count();
    }

    public function reconcileActive(): int
    {
        $active = NotificationEvent::query()
            ->where('source_kind', 'credit_card_statement')
            ->where('visibility', 'visible')
            ->whereNull('resolved_at')
            ->select(['user_id', 'source_id'])
            ->distinct()
            ->get();
        foreach ($active as $event) {
            $this->evaluate((int) $event->user_id, (int) $event->source_id);
        }

        return $active->count();
    }

    public function handleFact(NotificationProjectionFact $fact): void
    {
        $statement = CreditCardStatement::query()->with('creditCard')
            ->where('user_id', $fact->user_id)->find($fact->source_id);
        if ($statement === null) {
            $this->resolveMissing((int) $fact->user_id, (int) $fact->source_id);

            return;
        }
        $current = $this->stage($statement, $this->businessToday());
        $captured = $fact->qualified_type;
        $qualifiedAt = $fact->qualified_at === null ? null : CarbonImmutable::instance($fact->qualified_at);
        if ($captured !== null && $captured !== $current && in_array($captured, self::TYPES, true)) {
            $this->events->consume($this->candidate($statement, $captured, $qualifiedAt ?? CarbonImmutable::now()));
        }

        $this->evaluate((int) $fact->user_id, (int) $fact->source_id, $captured === $current ? $qualifiedAt : null);
    }

    private function resolveMissing(int $userId, int $statementId): void
    {
        NotificationEvent::query()->where('user_id', $userId)
            ->where('source_kind', 'credit_card_statement')
            ->where('source_id', $statementId)
            ->where('visibility', 'visible')
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'read_at' => now()]);
    }

    private function boundaryFor(CreditCardStatement $statement, ?string $stage): ?CarbonImmutable
    {
        $due = CarbonImmutable::parse($statement->due_date->toDateString(), RecurringDateRange::BUSINESS_TIMEZONE);
        $closed = CarbonImmutable::parse($statement->closing_date->toDateString(), RecurringDateRange::BUSINESS_TIMEZONE)->addDay();

        return match ($stage) {
            'statement_approaching' => $due->subDays(3)->greaterThan($closed) ? $due->subDays(3) : $closed,
            'statement_due_today' => $due,
            'statement_overdue' => $due->addDay(),
            default => null,
        };
    }

    private function stage(CreditCardStatement $statement, CarbonImmutable $today): ?string
    {
        if ($this->obligations->outstandingCentavos($statement) <= 0) {
            return null;
        }
        $status = $this->obligations->statusFor($statement, $today);
        if ($status === CreditCardStatementStatus::Open || $status === CreditCardStatementStatus::Paid) {
            return null;
        }
        if ($status === CreditCardStatementStatus::Overdue) {
            return 'statement_overdue';
        }
        $due = CarbonImmutable::parse($statement->due_date->toDateString(), RecurringDateRange::BUSINESS_TIMEZONE);
        $days = (int) $today->diffInDays($due, false);

        return match (true) {
            $days === 0 => 'statement_due_today',
            $days >= 1 && $days <= 3 => 'statement_approaching',
            default => null,
        };
    }

    private function candidate(CreditCardStatement $statement, string $type, CarbonImmutable $eventAt): NotificationCandidate
    {
        $card = $statement->creditCard;
        $masked = '•••• '.($card?->last_four ?: '••••');
        $period = $statement->period_from->format('m/Y');
        $due = $statement->due_date->format('d/m/Y');
        $amount = $this->obligations->outstandingCentavos($statement);
        $money = 'R$ '.number_format($amount / 100, 2, ',', '.');
        $titles = match ($type) {
            'statement_approaching' => ['pt-BR' => 'Fatura próxima do vencimento', 'en' => 'Statement due soon'],
            'statement_due_today' => ['pt-BR' => 'Fatura vence hoje', 'en' => 'Statement due today'],
            default => ['pt-BR' => 'Fatura vencida', 'en' => 'Overdue statement'],
        };
        $summary = [
            'pt-BR' => "Cartão {$masked}, fatura {$period}, vence {$due}; saldo atual {$money}.",
            'en' => "Card {$masked}, statement {$period}, due {$due}; current outstanding {$money}.",
        ];

        return new NotificationCandidate(
            userId: (int) $statement->user_id,
            type: $type,
            category: 'credit_cards',
            severity: $type === 'statement_overdue' ? 'critical' : 'attention',
            sourceKind: 'credit_card_statement',
            sourceId: (int) $statement->id,
            identity: ['stage' => $type],
            eventAt: $eventAt,
            snapshot: ['title' => $titles, 'summary' => $summary],
            currentContext: ['outstanding_centavos' => $amount, 'summary' => $summary],
        );
    }

    private function businessToday(): CarbonImmutable
    {
        return CarbonImmutable::now(RecurringDateRange::BUSINESS_TIMEZONE)->startOfDay();
    }
}
