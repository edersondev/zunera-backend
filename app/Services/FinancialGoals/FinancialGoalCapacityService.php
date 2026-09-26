<?php

declare(strict_types=1);

namespace App\Services\FinancialGoals;

use App\Enums\FinancialAccounts\AccountStatus;
use App\Exceptions\FinancialGoals\FinancialGoalStateException;
use App\Models\FinancialAccount;
use App\Models\FinancialGoal;
use Illuminate\Support\Facades\DB;

final class FinancialGoalCapacityService
{
    public function findOwnedActive(int $userId, int $accountId, bool $lock = false): FinancialAccount
    {
        $query = FinancialAccount::query()->where('user_id', $userId)->whereKey($accountId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $account = $query->firstOrFail();
        if ($account->status !== AccountStatus::Active) {
            throw FinancialGoalStateException::conflict('goal_account_unavailable', 'Choose an active account for this goal.');
        }

        return $account;
    }

    public function allocationForGoal(int $goalId): int
    {
        return (int) DB::table('financial_goal_activities')
            ->where('financial_goal_id', $goalId)
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('initial_allocation', 'allocated') THEN amount_centavos WHEN type = 'withdrawn' THEN -amount_centavos ELSE 0 END), 0) AS allocated")
            ->value('allocated');
    }

    public function designatedForAccount(int $userId, int $accountId): int
    {
        return (int) DB::table('financial_goals as g')
            ->join('financial_goal_activities as a', 'a.financial_goal_id', '=', 'g.id')
            ->where('g.user_id', $userId)
            ->where('g.financial_account_id', $accountId)
            ->whereIn('g.status', ['active', 'completed'])
            ->selectRaw("COALESCE(SUM(CASE WHEN a.type IN ('initial_allocation', 'allocated') THEN a.amount_centavos WHEN a.type = 'withdrawn' THEN -a.amount_centavos ELSE 0 END), 0) AS designated")
            ->value('designated');
    }

    public function assertAdditionFits(FinancialAccount $account, int $addition): void
    {
        $designated = $this->designatedForAccount((int) $account->user_id, (int) $account->id);
        if ($addition > (int) $account->current_balance_centavos - $designated) {
            throw FinancialGoalStateException::conflict('goal_account_capacity_exceeded', 'Account does not have enough unallocated money for this goal.');
        }
    }

    /** @return array<string, mixed> */
    public function coverage(FinancialGoal $goal, ?int $knownDesignated = null): array
    {
        if ($goal->financial_account_id === null) {
            return ['financial_account' => null, 'account_backing' => 'unverified'];
        }
        $account = $goal->financialAccount;
        $designated = $knownDesignated ?? $this->designatedForAccount((int) $goal->user_id, (int) $goal->financial_account_id);
        if ($account === null) {
            return [
                'financial_account' => ['id' => (int) $goal->financial_account_id, 'name' => $goal->account_name_snapshot, 'status' => 'unavailable', 'current_balance_centavos' => null, 'designated_centavos' => $designated, 'unallocated_centavos' => null, 'shortfall_centavos' => null],
                'account_backing' => 'inactive_or_unavailable',
            ];
        }
        $unallocated = (int) $account->current_balance_centavos - $designated;

        return [
            'financial_account' => ['id' => (int) $account->id, 'name' => $account->name, 'status' => $account->status->value, 'current_balance_centavos' => (int) $account->current_balance_centavos, 'designated_centavos' => $designated, 'unallocated_centavos' => $unallocated, 'shortfall_centavos' => max(-$unallocated, 0)],
            'account_backing' => $account->status !== AccountStatus::Active ? 'inactive_or_unavailable' : ($unallocated < 0 ? 'shortfall' : 'available'),
        ];
    }
}
