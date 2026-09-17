<?php

declare(strict_types=1);

namespace App\Services\FinancialDashboard;

use App\Data\FinancialDashboard\DashboardPeriodData;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionStatus;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringTransactions\RecurringScheduleCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Current and future expected activity inside the fixed, inclusive
 * 30-calendar-day horizon.
 * Eligible recurrence dates are projected as expected dates, and a rule date
 * already represented by a generated transaction is suppressed so an occurrence
 * appears once. Nothing here ever reaches realized totals.
 */
final class DashboardUpcomingActivityService
{
    public const int HORIZON_DAYS = 30;

    public function __construct(
        private readonly RecurringScheduleCalculator $schedule,
    ) {}

    /** @return array<string, mixed> */
    public function upcoming(User $user, ?string $businessDate = null): array
    {
        $business = $businessDate ?? DashboardPeriodData::businessDate();
        $from = CarbonImmutable::parse($business)->toDateString();
        $to = CarbonImmutable::parse($business)->addDays(self::HORIZON_DAYS - 1)->toDateString();

        $items = [];
        foreach ($this->pendingTransactions($user, $from, $to) as $transaction) {
            $items[] = [
                'source_kind' => 'pending_transaction',
                'expected_date' => $transaction->transaction_date->toDateString(),
                'type' => $transaction->type->value,
                'amount_centavos' => (int) $transaction->amount_centavos,
                'currency_code' => (string) $transaction->currency_code,
                'account' => $transaction->financialAccount,
                'category' => $transaction->category,
                'description' => (string) $transaction->description,
                'position' => [(int) $transaction->id, 0],
            ];
        }

        $suppressed = $this->generatedOccurrenceKeys($user, $from, $to);
        foreach ($this->eligibleRules($user) as $rule) {
            foreach ($this->schedule->futureDatesBetween($rule, $from, $to) as $date) {
                if (isset($suppressed[$rule->id.':'.$date])) {
                    continue;
                }

                $items[] = [
                    'source_kind' => 'recurring_occurrence',
                    'expected_date' => $date,
                    'type' => $rule->type->value,
                    'amount_centavos' => (int) $rule->amount_centavos,
                    'currency_code' => (string) $rule->currency_code,
                    'account' => $rule->financialAccount,
                    'category' => $rule->category,
                    'description' => (string) $rule->description,
                    'position' => [(int) $rule->id, 1],
                ];
            }
        }

        usort($items, fn (array $left, array $right): int => [$left['expected_date'], $left['position']] <=> [$right['expected_date'], $right['position']]);

        return [
            'from' => $from,
            'to' => $to,
            'items' => $items,
        ];
    }

    /** @return Collection<int, Transaction> */
    private function pendingTransactions(User $user, string $from, string $to): Collection
    {
        return Transaction::query()
            ->with(['financialAccount', 'category'])
            ->where('user_id', $user->id)
            ->whereNull('removed_at')
            ->where('status', TransactionStatus::Pending)
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, RecurringTransaction> */
    private function eligibleRules(User $user): Collection
    {
        return RecurringTransaction::query()
            ->with(['financialAccount', 'category'])
            ->where('user_id', $user->id)
            ->where('state', RecurrenceState::Active)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, bool> generated rule/schedule-date pairs keyed for suppression
     */
    private function generatedOccurrenceKeys(User $user, string $from, string $to): array
    {
        $rows = DB::table('transactions')
            ->where('user_id', $user->id)
            ->whereNull('removed_at')
            ->whereNotNull('recurring_transaction_id')
            ->whereNotNull('recurrence_scheduled_date')
            ->whereDate('recurrence_scheduled_date', '>=', $from)
            ->whereDate('recurrence_scheduled_date', '<=', $to)
            ->selectRaw('recurring_transaction_id, recurrence_scheduled_date')
            ->get();

        $keys = [];
        foreach ($rows as $row) {
            $date = substr((string) $row->recurrence_scheduled_date, 0, 10);
            $keys[(int) $row->recurring_transaction_id.':'.$date] = true;
        }

        return $keys;
    }
}
