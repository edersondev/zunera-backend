<?php

declare(strict_types=1);

namespace App\Services\RecurringTransactions;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Enums\RecurringTransactions\RecurrenceDestinationType;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionStatus;
use App\Exceptions\CreditCards\CreditCardStateException;
use App\Exceptions\RecurringTransactions\RecurrenceStateException;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\RecurringCardOccurrence;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Services\CreditCards\CreditCardPurchaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Due-date processor. Account rules keep their existing pending-transaction
 * source; card rules reserve one durable occurrence per eligible date and,
 * for automatic generation, record a single source-linked card purchase.
 */
final class RecurringOccurrenceService
{
    public function __construct(
        private readonly RecurringScheduleCalculator $calculator,
        private readonly CreditCardPurchaseService $purchases,
    ) {}

    /** @return array{processed: int, created: int, ended: int} */
    public function processDueRules(?string $businessDate = null): array
    {
        $today = RecurringDateRange::processingDate($businessDate);

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
            ->where(static function (Builder $query): void {
                $query->where('destination_type', RecurrenceDestinationType::FinancialAccount->value)
                    ->orWhereColumn('schedule_cursor', '>', 'end_date');
            })
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
        $today = RecurringDateRange::processingDate($businessDate);
        $rule = RecurringTransaction::query()->lockForUpdate()->find($ruleId);
        if (! $rule instanceof RecurringTransaction || ! $rule->isActive()) {
            return 0;
        }

        if ($rule->isAccountDestination()) {
            return DB::transaction(fn (): int => $this->processAccountRule($rule, $today));
        }

        return $this->processCardRule($rule, $today);
    }

    /**
     * Represents every eligible card date already due through today before an
     * owner-initiated edit, pause, or end. Throws a retryable conflict when a
     * due date remains unrepresented so the rule mutation can be aborted.
     */
    public function representDueDates(RecurringTransaction $rule): void
    {
        if (! $rule->isCardDestination() || ! $rule->isActive()) {
            return;
        }

        $today = RecurringDateRange::businessDate();
        $this->processCardRule($rule, $today);

        $this->assertDueDatesRepresented($rule);
    }

    /** Recheck due-date coverage while the owner mutation holds the rule lock. */
    public function assertDueDatesRepresented(RecurringTransaction $rule): void
    {
        if (! $rule->isCardDestination() || ! $rule->isActive()) {
            return;
        }

        $today = RecurringDateRange::businessDate();
        $endDate = $rule->endDateOrNull();
        $expired = $endDate !== null && $endDate < $today;
        $windowEnd = $expired ? (string) $endDate : $today;
        $from = max($rule->eligibility_starts_on->toDateString(), $rule->schedule_cursor->toDateString());

        foreach ($this->calculator->datesBetween($rule, $from, $windowEnd) as $scheduledDate) {
            $exists = RecurringCardOccurrence::query()
                ->where('recurring_transaction_id', $rule->id)
                ->whereDate('scheduled_date', $scheduledDate)
                ->exists();
            if (! $exists) {
                throw RecurrenceStateException::dueProcessingIncomplete();
            }
        }
    }

    private function processAccountRule(RecurringTransaction $rule, string $today): int
    {
        $endDate = $rule->endDateOrNull();
        $expired = $endDate !== null && $endDate < $today;
        $windowEnd = $expired ? (string) $endDate : $today;

        $from = max($rule->eligibility_starts_on->toDateString(), $rule->schedule_cursor->toDateString());
        $created = 0;
        foreach ($this->calculator->datesBetween($rule, $from, $windowEnd) as $scheduledDate) {
            if ($this->generateAccountOccurrence($rule, $scheduledDate)) {
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
    }

    private function processCardRule(RecurringTransaction $rule, string $today): int
    {
        $endDate = $rule->endDateOrNull();
        $expired = $endDate !== null && $endDate < $today;
        $windowEnd = $expired ? (string) $endDate : $today;
        $from = max($rule->eligibility_starts_on->toDateString(), $rule->schedule_cursor->toDateString());

        $created = 0;
        $allDatesRepresented = true;
        foreach ($this->calculator->datesBetween($rule, $from, $windowEnd) as $scheduledDate) {
            try {
                if ($this->processCardDate((int) $rule->id, $scheduledDate)) {
                    $created++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $allDatesRepresented = false;
                break;
            }
        }

        if (! $allDatesRepresented) {
            return $created;
        }

        DB::transaction(function () use ($rule, $today, $expired): void {
            $locked = RecurringTransaction::query()->lockForUpdate()->find($rule->id);
            if (! $locked instanceof RecurringTransaction || ! $locked->isActive()) {
                return;
            }
            if ($locked->schedule_cursor->toDateString() < $today) {
                $locked->schedule_cursor = $today;
            }
            if ($expired) {
                $locked->state = RecurrenceState::Ended;
                $locked->paused_reason = null;
                $locked->ended_at = now();
            }
            $locked->save();
        });

        return $created;
    }

    /** Reserves and processes one card date. Returns true when a new occurrence was represented. */
    private function processCardDate(int $ruleId, string $scheduledDate): bool
    {
        $occurrence = DB::transaction(function () use ($ruleId, $scheduledDate): ?RecurringCardOccurrence {
            $rule = RecurringTransaction::query()->lockForUpdate()->find($ruleId);
            if (! $rule instanceof RecurringTransaction || ! $rule->isActive()) {
                return null;
            }

            $existing = RecurringCardOccurrence::query()
                ->where('recurring_transaction_id', $ruleId)
                ->whereDate('scheduled_date', $scheduledDate)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof RecurringCardOccurrence) {
                $this->advanceCursor($rule, $scheduledDate);

                return $existing;
            }

            $created = $this->createOccurrence($rule, $scheduledDate);
            $this->advanceCursor($rule, $scheduledDate);

            return $created;
        });

        if (! $occurrence instanceof RecurringCardOccurrence) {
            return false;
        }

        if ($occurrence->state !== CardOccurrenceState::Expected) {
            return true;
        }

        if ($occurrence->generation_mode_snapshot === CardGenerationMode::Confirmation) {
            return true;
        }

        try {
            $this->recordAutomatic($occurrence);
        } catch (CreditCardStateException $exception) {
            if ($exception->isOverLimitConfirmationRequired()) {
                $this->markState($occurrence->id, CardOccurrenceState::AwaitingOverLimit, null);

                return true;
            }
            $this->markState($occurrence->id, CardOccurrenceState::Failed, 'card_unavailable');
        } catch (Throwable) {
            $this->markState($occurrence->id, CardOccurrenceState::Failed, 'purchase_recording_failed');
        }

        return true;
    }

    private function recordAutomatic(RecurringCardOccurrence $occurrence): void
    {
        $this->purchases->recordOccurrencePurchase(
            $occurrence->user,
            $occurrence,
            (int) $occurrence->credit_card_id_original,
            (int) $occurrence->category_id_original,
            (int) $occurrence->scheduled_amount_centavos,
            $occurrence->scheduled_date->toDateString(),
            (string) $occurrence->description_snapshot,
            $occurrence->notes_snapshot,
            false,
            null,
        );
    }

    private function createOccurrence(RecurringTransaction $rule, string $scheduledDate): RecurringCardOccurrence
    {
        $card = $rule->creditCard()->first();
        $category = $rule->category()->first();

        return RecurringCardOccurrence::query()->create([
            'user_id' => $rule->user_id,
            'recurring_transaction_id' => $rule->id,
            'scheduled_date' => $scheduledDate,
            'generation_mode_snapshot' => $rule->generationModeOrAutomatic() ?? CardGenerationMode::Automatic,
            'scheduled_amount_centavos' => $rule->amount_centavos,
            'description_snapshot' => $rule->description,
            'notes_snapshot' => $rule->notes,
            'category_id_original' => $rule->category_id,
            'credit_card_id_original' => $rule->credit_card_id,
            'card_identity_snapshot' => [
                'name' => $card?->name,
                'institution_name' => $card?->institution_name,
                'last_four' => $card?->last_four,
                'status' => $card?->status?->value,
            ],
            'category_name_snapshot' => $category?->name ?? '',
            'state' => CardOccurrenceState::Expected,
        ]);
    }

    private function advanceCursor(RecurringTransaction $rule, string $scheduledDate): void
    {
        if ($rule->schedule_cursor->toDateString() < $scheduledDate) {
            $rule->schedule_cursor = $scheduledDate;
            $rule->save();
        }
    }

    private function markState(int $occurrenceId, CardOccurrenceState $state, ?string $failureCode): void
    {
        RecurringCardOccurrence::query()->whereKey($occurrenceId)->update([
            'state' => $state->value,
            'failure_code' => $failureCode,
            'last_attempt_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function generateAccountOccurrence(RecurringTransaction $rule, string $scheduledDate): bool
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
            FinancialAccount::query()
                ->whereKey($rule->financial_account_id)
                ->update(['has_financial_movements' => true]);
            Category::query()
                ->whereKey($rule->category_id)
                ->update(['has_financial_transactions' => true]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
