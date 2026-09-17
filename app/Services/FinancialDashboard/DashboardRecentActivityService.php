<?php

declare(strict_types=1);

namespace App\Services\FinancialDashboard;

use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;

/**
 * Newest eligible activity across income, expense, and transfer records,
 * including pending movements. The ten-item limit is a product rule and is
 * never caller-controlled; full history stays available through Financial
 * History.
 */
final class DashboardRecentActivityService
{
    public const int LIMIT = 10;

    /**
     * @return list<array{kind: string, model: Transaction|Transfer}>
     */
    public function recent(User $user): array
    {
        $transactions = Transaction::query()
            ->with(['financialAccount', 'category'])
            ->where('user_id', $user->id)
            ->whereNull('removed_at')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $transfers = Transfer::query()
            ->with(['sourceAccount', 'destinationAccount'])
            ->where('user_id', $user->id)
            ->whereNull('removed_at')
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $entries = [];
        foreach ($transactions as $transaction) {
            $entries[] = ['kind' => 'transaction', 'model' => $transaction];
        }
        foreach ($transfers as $transfer) {
            $entries[] = ['kind' => 'transfer', 'model' => $transfer];
        }

        usort($entries, function (array $left, array $right): int {
            [$leftDate, $leftId] = $this->position($left);
            [$rightDate, $rightId] = $this->position($right);

            return [$rightDate, $rightId] <=> [$leftDate, $leftId];
        });

        return array_slice($entries, 0, self::LIMIT);
    }

    /**
     * @param  array{kind: string, model: Transaction|Transfer}  $entry
     * @return array{0: string, 1: int}
     */
    private function position(array $entry): array
    {
        $model = $entry['model'];

        if ($model instanceof Transfer) {
            return [$model->transfer_date->toDateString(), (int) $model->id];
        }

        return [$model->transaction_date->toDateString(), (int) $model->id];
    }
}
