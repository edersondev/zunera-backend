<?php

declare(strict_types=1);

namespace App\Services\FinancialGoals;

use App\Models\FinancialGoal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class FinancialGoalQueryService
{
    public function __construct(private readonly FinancialGoalCapacityService $capacity, private readonly GoalContributionCalculator $calculator) {}

    public function findOwned(int $userId, int $goalId): FinancialGoal
    {
        return FinancialGoal::query()->with('financialAccount')->where('user_id', $userId)->findOrFail($goalId);
    }

    public function list(int $userId, string $status, int $perPage): LengthAwarePaginator
    {
        $query = FinancialGoal::query()->with('financialAccount')->where('user_id', $userId);
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
        $this->prime($page->getCollection());

        return $page;
    }

    public function activities(int $userId, int $goalId, int $perPage): LengthAwarePaginator
    {
        $goal = $this->findOwned($userId, $goalId);

        return $goal->activities()->where('user_id', $userId)->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage);
    }

    /** @return array<string, mixed> */
    public function summary(int $userId): array
    {
        $goals = FinancialGoal::query()->with('financialAccount')->where('user_id', $userId)->whereIn('status', ['active', 'completed'])->get();
        $this->prime($goals);
        $counts = FinancialGoal::query()->where('user_id', $userId)->selectRaw('status, COUNT(*) AS count')->groupBy('status')->pluck('count', 'status');
        $result = [
            'active_count' => (int) ($counts['active'] ?? 0),
            'completed_count' => (int) ($counts['completed'] ?? 0),
            'archived_count' => (int) ($counts['archived'] ?? 0),
            'active_target_centavos' => 0,
            'active_allocated_centavos' => 0,
            'active_remaining_centavos' => 0,
            'active_excess_centavos' => 0,
            'active_unverified_centavos' => 0,
            'attention_counts' => [
                'overdue_underfunded_active_goals' => 0,
                'shortfall_linked_goals' => 0,
                'inactive_or_unavailable_linked_goals' => 0,
            ],
            'linked_accounts' => [],
        ];
        $accounts = [];
        foreach ($goals as $goal) {
            $projection = $this->project($goal);
            if ($goal->status === 'active') {
                $result['active_target_centavos'] += $projection['target_centavos'];
                $result['active_allocated_centavos'] += $projection['allocated_centavos'];
                $result['active_remaining_centavos'] += $projection['remaining_centavos'];
                $result['active_excess_centavos'] += $projection['excess_centavos'];
                if ($projection['account_backing'] === 'unverified') {
                    $result['active_unverified_centavos'] += $projection['allocated_centavos'];
                }
                if ($projection['target_date_state'] === 'overdue' && $projection['remaining_centavos'] > 0) {
                    $result['attention_counts']['overdue_underfunded_active_goals']++;
                }
            }
            if (($projection['financial_account']['shortfall_centavos'] ?? 0) > 0) {
                $result['attention_counts']['shortfall_linked_goals']++;
            }
            if ($projection['account_backing'] === 'inactive_or_unavailable') {
                $result['attention_counts']['inactive_or_unavailable_linked_goals']++;
            }
            if ($projection['financial_account'] !== null) {
                $accounts[$projection['financial_account']['id']] = $projection['financial_account'];
            }
        }
        $result['linked_accounts'] = array_values($accounts);

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function dashboardGoals(int $userId): array
    {
        $today = now('America/Sao_Paulo')->toDateString();
        $goals = FinancialGoal::query()->with('financialAccount')->where('user_id', $userId)->where('status', 'active')
            ->orderByRaw('CASE WHEN target_date < ? THEN 0 WHEN target_date IS NULL THEN 2 ELSE 1 END', [$today])
            ->orderBy('target_date')->orderBy('name')->orderBy('id')->limit(3)->get();

        $this->prime($goals);

        return $goals->map(function (FinancialGoal $goal): array {
            $projection = $this->project($goal);

            return array_intersect_key($projection, array_flip(['id', 'name', 'allocated_centavos', 'target_centavos', 'remaining_centavos', 'progress_percentage', 'target_date']));
        })->all();
    }

    /** @return array{remaining_centavos: int, excess_centavos: int, progress_percentage: float} */
    public static function progress(int $target, int $allocated): array
    {
        return [
            'remaining_centavos' => max($target - $allocated, 0),
            'excess_centavos' => max($allocated - $target, 0),
            'progress_percentage' => $allocated / $target * 100,
        ];
    }

    /** @param Collection<int, FinancialGoal> $goals */
    private function prime(Collection $goals): void
    {
        if ($goals->isEmpty()) {
            return;
        }

        $goalIds = $goals->pluck('id')->all();
        $allocations = DB::table('financial_goal_activities')
            ->whereIn('financial_goal_id', $goalIds)
            ->selectRaw("financial_goal_id, COALESCE(SUM(CASE WHEN type IN ('initial_allocation', 'allocated') THEN amount_centavos WHEN type = 'withdrawn' THEN -amount_centavos ELSE 0 END), 0) AS allocated")
            ->groupBy('financial_goal_id')->pluck('allocated', 'financial_goal_id');

        $accountIds = $goals->pluck('financial_account_id')->filter()->unique()->values()->all();
        $designations = empty($accountIds) ? collect() : DB::table('financial_goals as g')
            ->join('financial_goal_activities as a', 'a.financial_goal_id', '=', 'g.id')
            ->where('g.user_id', (int) $goals->first()->user_id)
            ->whereIn('g.financial_account_id', $accountIds)
            ->whereIn('g.status', ['active', 'completed'])
            ->selectRaw("g.financial_account_id, COALESCE(SUM(CASE WHEN a.type IN ('initial_allocation', 'allocated') THEN a.amount_centavos WHEN a.type = 'withdrawn' THEN -a.amount_centavos ELSE 0 END), 0) AS designated")
            ->groupBy('g.financial_account_id')->pluck('designated', 'g.financial_account_id');

        foreach ($goals as $goal) {
            $goal->setAttribute('goal_cached_allocated', (int) ($allocations[$goal->id] ?? 0));
            if ($goal->financial_account_id !== null) {
                $goal->setAttribute('goal_cached_designated', (int) ($designations[$goal->financial_account_id] ?? 0));
            }
        }
    }

    /** @return array<string, mixed> */
    public function project(FinancialGoal $goal): array
    {
        $allocated = array_key_exists('goal_cached_allocated', $goal->getAttributes())
            ? (int) $goal->getAttribute('goal_cached_allocated')
            : $this->capacity->allocationForGoal((int) $goal->id);
        $progress = self::progress((int) $goal->target_centavos, $allocated);
        $knownDesignated = array_key_exists('goal_cached_designated', $goal->getAttributes())
            ? (int) $goal->getAttribute('goal_cached_designated')
            : null;
        $coverage = $this->capacity->coverage($goal, $knownDesignated);
        $guidance = $this->calculator->calculate($goal->target_date?->toDateString(), $progress['remaining_centavos'], $goal->status);

        return [
            'id' => (int) $goal->id,
            'name' => $goal->name,
            'status' => $goal->status,
            'target_centavos' => (int) $goal->target_centavos,
            'allocated_centavos' => $allocated,
            ...$progress,
            'currency_code' => 'BRL',
            'target_date' => $goal->target_date?->toDateString(),
            ...$guidance,
            ...$coverage,
            'description' => $goal->description,
            'completed_at' => $goal->completed_at?->toIso8601String(),
            'archived_at' => $goal->archived_at?->toIso8601String(),
            'created_at' => $goal->created_at?->toIso8601String(),
            'updated_at' => $goal->updated_at?->toIso8601String(),
        ];
    }
}
