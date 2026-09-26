<?php

declare(strict_types=1);

namespace App\Services\FinancialGoals;

use App\Data\FinancialGoals\GoalInput;
use App\Data\FinancialGoals\GoalUpdateInput;
use App\Exceptions\FinancialGoals\FinancialGoalStateException;
use App\Models\FinancialAccount;
use App\Models\FinancialGoal;
use App\Models\FinancialGoalActivity;

final class FinancialGoalMutationService
{
    public function __construct(
        private readonly FinancialGoalIdempotencyService $idempotency,
        private readonly FinancialGoalCapacityService $capacity,
        private readonly FinancialGoalQueryService $query,
    ) {}

    /** @return array{status: int, body: array<string, mixed>} */
    public function create(int $userId, GoalInput $input, string $key): array
    {
        return $this->idempotency->execute($userId, $key, 'create', $input->fingerprintPayload(), function () use ($userId, $input): array {
            $account = $input->financialAccountId === null ? null : $this->capacity->findOwnedActive($userId, $input->financialAccountId, true);
            if ($account !== null && $input->initialAllocatedCentavos > 0) {
                $this->capacity->assertAdditionFits($account, $input->initialAllocatedCentavos);
            }
            $goal = FinancialGoal::create([
                'user_id' => $userId,
                'name' => $input->name,
                'target_centavos' => $input->targetCentavos,
                'target_date' => $input->targetDate,
                'financial_account_id' => $account?->id,
                'account_name_snapshot' => $account?->name,
                'description' => $input->description,
                'status' => 'active',
            ]);
            $this->activity($goal, 'created', null, $account?->id, $account?->name);
            if ($input->initialAllocatedCentavos > 0) {
                $this->activity($goal, 'initial_allocation', $input->initialAllocatedCentavos, $account?->id, $account?->name);
            }

            return ['goal_id' => (int) $goal->id, 'status' => 201, 'body' => ['data' => $this->query->project($goal)]];
        });
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function lifecycle(int $userId, int $goalId, string $action, string $key): array
    {
        if (! in_array($action, ['complete', 'reopen', 'archive', 'restore'], true)) {
            throw new \InvalidArgumentException('Unsupported goal lifecycle action.');
        }

        return $this->idempotency->execute($userId, $key, $action, ['goal_id' => $goalId], function () use ($userId, $goalId, $action): array {
            $goal = FinancialGoal::query()->where('user_id', $userId)->whereKey($goalId)->lockForUpdate()->firstOrFail();
            $from = $goal->status;
            $requiredStatus = $action === 'reopen' ? 'completed' : ($action === 'restore' ? 'archived' : 'active');
            if ($from !== $requiredStatus) {
                throw FinancialGoalStateException::conflict('goal_invalid_transition', 'This goal cannot make that status change.');
            }
            $allocated = $this->capacity->allocationForGoal($goalId);
            if ($action === 'complete') {
                if ($allocated < (int) $goal->target_centavos) {
                    throw FinancialGoalStateException::conflict('goal_target_not_reached', 'Allocate the target amount before completing this goal.');
                }
                if ($goal->financial_account_id !== null) {
                    FinancialAccount::query()->where('user_id', $userId)->whereKey($goal->financial_account_id)->lockForUpdate()->first();
                    $coverage = $this->capacity->coverage($goal);
                    if ($coverage['account_backing'] === 'inactive_or_unavailable') {
                        throw FinancialGoalStateException::conflict('goal_account_unavailable', 'Choose an active account before completing this goal.');
                    }
                    if ($coverage['account_backing'] === 'shortfall') {
                        throw FinancialGoalStateException::conflict('goal_account_shortfall', 'Resolve the linked account shortfall before completing this goal.');
                    }
                }
                $goal->status = 'completed';
                $goal->completed_at = now();
            } elseif ($action === 'reopen') {
                $goal->status = 'active';
                $goal->completed_at = null;
            } elseif ($action === 'archive') {
                if ($allocated !== 0) {
                    throw FinancialGoalStateException::conflict('goal_archive_requires_zero_allocation', 'Withdraw the full allocation before archiving this goal.');
                }
                $goal->status = 'archived';
                $goal->archived_at = now();
            } else {
                $goal->status = 'active';
                $goal->archived_at = null;
            }
            $goal->save();
            $eventType = ['complete' => 'completed', 'reopen' => 'reopened', 'archive' => 'archived', 'restore' => 'restored'][$action];
            $this->activity($goal, $eventType, null, $goal->financial_account_id, $goal->account_name_snapshot, ['from' => $from, 'to' => $goal->status]);

            return ['goal_id' => $goalId, 'status' => 200, 'body' => ['data' => $this->query->project($goal)]];
        });
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function update(int $userId, int $goalId, GoalUpdateInput $input, string $key): array
    {
        return $this->idempotency->execute($userId, $key, 'update', ['goal_id' => $goalId, 'changes' => $input->changes], function () use ($userId, $goalId, $input): array {
            $goal = FinancialGoal::query()->where('user_id', $userId)->whereKey($goalId)->lockForUpdate()->firstOrFail();
            if ($goal->status !== 'active') {
                throw FinancialGoalStateException::conflict($goal->status === 'completed' ? 'goal_completed_requires_reopen' : 'goal_archived_requires_restore', 'Reopen or restore this goal before editing it.');
            }
            $oldAccountId = $goal->financial_account_id === null ? null : (int) $goal->financial_account_id;
            $newAccountId = $input->has('financial_account_id') ? $input->get('financial_account_id') : $oldAccountId;
            $ids = array_values(array_unique(array_filter([$oldAccountId, $newAccountId], static fn ($id) => $id !== null)));
            sort($ids, SORT_NUMERIC);
            $accounts = [];
            foreach ($ids as $id) {
                $accounts[$id] = FinancialAccount::query()->where('user_id', $userId)->whereKey($id)->lockForUpdate()->first();
            }
            $newAccount = $newAccountId === null ? null : ($accounts[$newAccountId] ?? null);
            if ($input->has('financial_account_id') && $newAccountId !== null) {
                $newAccount = $this->capacity->findOwnedActive($userId, (int) $newAccountId, true);
            }
            if ($newAccountId !== $oldAccountId && $newAccount !== null) {
                $this->capacity->assertAdditionFits($newAccount, $this->capacity->allocationForGoal($goalId));
            }
            $before = $goal->only(['name', 'target_centavos', 'target_date', 'financial_account_id', 'description']);
            foreach ($input->changes as $field => $value) {
                $goal->{$field} = $value;
            }
            if ($newAccountId !== $oldAccountId) {
                $goal->account_name_snapshot = $newAccount?->name;
            }
            $goal->save();
            $goal->unsetRelation('financialAccount');
            $eventType = $newAccountId !== $oldAccountId ? 'account_changed' : 'goal_updated';
            $this->activity($goal, $eventType, null, $newAccount?->id, $newAccount?->name, ['before' => $before, 'changes' => $input->changes]);

            return ['goal_id' => $goalId, 'status' => 200, 'body' => ['data' => $this->query->project($goal)]];
        });
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function moneyAction(int $userId, int $goalId, string $type, int $amount, string $key): array
    {
        if (! in_array($type, ['allocated', 'withdrawn'], true)) {
            throw new \InvalidArgumentException('Unsupported goal money action.');
        }

        return $this->idempotency->execute($userId, $key, $type, ['goal_id' => $goalId, 'amount_centavos' => $amount], function () use ($userId, $goalId, $type, $amount): array {
            $goal = FinancialGoal::query()->where('user_id', $userId)->whereKey($goalId)->lockForUpdate()->firstOrFail();
            if ($goal->status !== 'active') {
                throw FinancialGoalStateException::conflict($goal->status === 'completed' ? 'goal_completed_requires_reopen' : 'goal_archived_requires_restore', 'Reopen or restore this goal before changing its allocation.');
            }
            $account = $goal->financial_account_id === null ? null : FinancialAccount::query()->where('user_id', $userId)->whereKey($goal->financial_account_id)->lockForUpdate()->first();
            $allocated = $this->capacity->allocationForGoal($goalId);
            if ($type === 'allocated' && $account !== null) {
                $account = $this->capacity->findOwnedActive($userId, (int) $account->id, true);
                $this->capacity->assertAdditionFits($account, $amount);
            } elseif ($type === 'allocated' && $goal->financial_account_id !== null) {
                throw FinancialGoalStateException::conflict('goal_account_unavailable', 'Choose an available account before adding money.');
            }
            if ($type === 'withdrawn' && $amount > $allocated) {
                throw FinancialGoalStateException::conflict('goal_withdrawal_exceeds_allocation', 'Withdrawal exceeds the goal allocation.');
            }
            $this->activity($goal, $type, $amount, $account?->id, $account?->name ?? $goal->account_name_snapshot);

            return ['goal_id' => $goalId, 'status' => 200, 'body' => ['data' => $this->query->project($goal)]];
        });
    }

    private function activity(FinancialGoal $goal, string $type, ?int $amount, ?int $accountId, ?string $accountName, ?array $details = null): void
    {
        $at = now();
        FinancialGoalActivity::create([
            'financial_goal_id' => $goal->id,
            'user_id' => $goal->user_id,
            'type' => $type,
            'amount_centavos' => $amount,
            'financial_account_id_at_time' => $accountId,
            'account_name_at_time' => $accountName,
            'details' => $details,
            'occurred_at' => $at,
            'business_date' => $at->copy()->timezone('America/Sao_Paulo')->toDateString(),
        ]);
    }
}
