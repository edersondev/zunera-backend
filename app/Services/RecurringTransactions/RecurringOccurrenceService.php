<?php

declare(strict_types=1);

namespace App\Services\RecurringTransactions;

use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionStatus;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Due-date processor: one pending, source-linked ordinary transaction per
 * eligible scheduled date, catch-up for active downtime, and automatic end for
 * rules whose inclusive end date has passed.
 */
final class RecurringOccurrenceService
{
    public function __construct(private readonly RecurringScheduleCalculator $calculator) {}

    /** @return array{processed: int, created: int, ended: int} */
    public function processDueRules(?string $businessDate = null): array
    {
        $today = $businessDate ?? RecurringDateRange::businessDate();

        $ruleIds = RecurringTransaction::query()
            ->active()
            ->whereDate('eligibility_starts_on', '<=', $today)
            ->whereDate('schedule_cursor', '<=', $today)
            ->orderBy('id')
            ->pluck('id');

        $created = 0;
        foreach ($ruleIds as $ruleId) {
            $created += $this->processRule((int) $ruleId, $today);
        }

        // Safety net for rules that became due for ending without a processing window.
        $ended = $this->endExpiredRules($today);

        return ['processed' => $ruleIds->count(), 'created' => $created, 'ended' => $ended];
    }

    /** Ends active rules whose inclusive end date already passed. */
    public function endExpiredRules(string $businessDate): int
    {
        return RecurringTransaction::query()
            ->active()
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', $businessDate)
            ->update([
                'state' => RecurrenceState::Ended->value,
                'paused_reason' => null,
                'ended_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** Creates every pending occurrence still missing for one rule. Returns created rows. */
    public function processRule(int $ruleId, ?string $businessDate = null): int
    {
        $today = $businessDate ?? RecurringDateRange::businessDate();

        return DB::transaction(function () use ($ruleId, $today): int {
            $rule = RecurringTransaction::query()->lockForUpdate()->find($ruleId);
            if (! $rule instanceof RecurringTransaction || ! $rule->isActive()) {
                return 0;
            }

            $endDate = $rule->endDateOrNull();
            $expired = $endDate !== null && $endDate < $today;
            // A rule that already passed its inclusive end date still recovers every
            // eligible date missed while it was active, then ends for good.
            $windowEnd = $expired ? (string) $endDate : $today;

            $from = max($rule->eligibility_starts_on->toDateString(), $rule->schedule_cursor->toDateString());
            $created = 0;
            foreach ($this->calculator->datesBetween($rule, $from, $windowEnd) as $scheduledDate) {
                if ($this->generateOccurrence($rule, $scheduledDate)) {
                    $created++;
                }
            }

            if ($rule->schedule_cursor->toDateString() < $today) {
                $rule->schedule_cursor = $today;
            }
            if ($expired) {
                $rule->state = RecurrenceState::Ended;
                $rule->paused_reason = null;
                $rule->ended_at = now();
            }
            $rule->save();

            return $created;
        });
    }

    private function generateOccurrence(RecurringTransaction $rule, string $scheduledDate): bool
    {
        $exists = Transaction::query()
            ->where('recurring_transaction_id', $rule->id)
            ->whereDate('recurrence_scheduled_date', $scheduledDate)
            ->exists();
        if ($exists) {
            return false;
        }

        try {
            Transaction::query()->create([
                'user_id' => $rule->user_id,
                'financial_account_id' => $rule->financial_account_id,
                'category_id' => $rule->category_id,
                'type' => $rule->type,
                'status' => TransactionStatus::Pending,
                'description' => $rule->description,
                'notes' => $rule->notes,
                'amount_centavos' => $rule->amount_centavos,
                'currency_code' => $rule->currency_code,
                'transaction_date' => $scheduledDate,
                'removed_at' => null,
                'recurring_transaction_id' => $rule->id,
                'recurrence_scheduled_date' => $scheduledDate,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent processor created the same rule/date pair first.
            return false;
        }

        return true;
    }
}
