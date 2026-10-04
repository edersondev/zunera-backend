<?php

declare(strict_types=1);

namespace App\Services\AccountData;

use App\Exceptions\ProfilePasswordThrottledException;
use App\Models\User;
use App\Services\Authentication\AuthenticationAudit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AccountDataService
{
    public const RECORD_TYPES = [
        'financial_accounts', 'categories', 'transactions', 'transfers',
        'recurring_transactions', 'monthly_budgets', 'budget_category_plans',
        'credit_cards', 'credit_card_purchases', 'credit_card_statements',
        'credit_card_installments', 'credit_card_statement_payments',
        'credit_card_credit_events', 'credit_card_credit_applications',
        'recurring_card_occurrences', 'financial_goals', 'financial_goal_activities',
    ];

    private const DELETE_ORDER = [
        'credit_card_credit_applications', 'credit_card_credit_events',
        'credit_card_installments', 'credit_card_statement_payments',
        'credit_card_purchases', 'recurring_card_occurrences',
        'credit_card_statements', 'financial_goal_activities',
        'budget_category_plans', 'transactions', 'transfers',
        'recurring_transactions', 'financial_goals', 'monthly_budgets',
        'credit_cards', 'financial_accounts', 'categories',
    ];

    private const MUTATION_TABLES = [
        'transaction_mutation_requests', 'transfer_mutation_requests',
        'recurring_mutation_requests', 'credit_card_mutation_requests',
        'financial_goal_mutation_requests',
    ];

    public function __construct(private readonly AuthenticationAudit $audit) {}

    /** @return array{id: int, created_at: string, record_count: int} */
    public function archive(User $user): array
    {
        $result = DB::transaction(function () use ($user): array {
            $this->lockUser($user);
            $archiveId = (int) DB::table('financial_data_archives')->insertGetId([
                'user_id' => $user->id,
                'record_count' => 0,
                'created_at' => now(),
            ]);
            $recordCount = 0;

            foreach (self::RECORD_TYPES as $table) {
                $this->ownedRows($table, (int) $user->id)
                    ->orderBy('id')
                    ->chunkById(100, function ($rows) use ($archiveId, $table, &$recordCount): void {
                        $records = [];
                        foreach ($rows as $row) {
                            $records[] = [
                                'financial_data_archive_id' => $archiveId,
                                'record_type' => $table,
                                'source_id' => $row->id,
                                'payload' => json_encode((array) $row, JSON_THROW_ON_ERROR),
                            ];
                        }
                        DB::table('financial_data_archive_records')->insert($records);
                        $recordCount += count($records);
                    });
            }

            DB::table('financial_data_archives')->where('id', $archiveId)->update(['record_count' => $recordCount]);
            $this->clearLiveData((int) $user->id);

            return $this->archiveMetadata($archiveId);
        }, 3);

        $this->audit->record('financial_data_archived', ['user_id' => $user->id, 'archive_id' => $result['id']]);

        return $result;
    }

    public function deleteAll(User $user, string $currentPassword): void
    {
        $key = 'auth:profile-password:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new ProfilePasswordThrottledException(RateLimiter::availableIn($key));
        }

        DB::transaction(function () use ($user, $currentPassword, $key): void {
            $locked = $this->lockUser($user);
            if (! Hash::check($currentPassword, $locked->password)) {
                RateLimiter::hit($key, 900);
                throw ValidationException::withMessages([
                    'current_password' => [__('auth.current_password_invalid')],
                ]);
            }

            $this->clearLiveData((int) $user->id);
            DB::table('financial_data_archives')->where('user_id', $user->id)->delete();
        }, 3);

        RateLimiter::clear($key);
        $this->audit->record('financial_data_deleted', ['user_id' => $user->id]);
    }

    /** @return array<int, array{id: int, created_at: string, record_count: int}> */
    public function archives(User $user): array
    {
        return DB::table('financial_data_archives')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get(['id', 'created_at', 'record_count'])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'created_at' => (string) $row->created_at,
                'record_count' => (int) $row->record_count,
            ])->all();
    }

    /** @return array{data: array<int, array{type: string, source_id: int, payload: array<string, mixed>}>, meta: array{current_page: int, last_page: int, total: int}} */
    public function records(User $user, int $archiveId, string $type, int $page): array
    {
        if (! in_array($type, self::RECORD_TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['Unsupported record type.']]);
        }

        $archive = DB::table('financial_data_archives')->where('user_id', $user->id)->find($archiveId);
        if ($archive === null) {
            throw new NotFoundHttpException('Archive not found.');
        }

        $records = DB::table('financial_data_archive_records')
            ->where('financial_data_archive_id', $archiveId)
            ->where('record_type', $type)
            ->orderByDesc('source_id')
            ->paginate(25, ['record_type', 'source_id', 'payload'], 'page', $page);

        return [
            'data' => array_map(fn ($row): array => [
                'type' => (string) $row->record_type,
                'source_id' => (int) $row->source_id,
                'payload' => json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR),
            ], $records->items()),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'total' => $records->total(),
            ],
        ];
    }

    private function lockUser(User $user): User
    {
        return User::query()->lockForUpdate()->findOrFail($user->id);
    }

    private function ownedRows(string $table, int $userId): Builder
    {
        $query = DB::table($table);
        if ($table === 'budget_category_plans') {
            return $query->whereIn('monthly_budget_id', function ($subquery) use ($userId): void {
                $subquery->select('id')->from('monthly_budgets')->where('user_id', $userId);
            });
        }
        if ($table === 'categories') {
            return $query->where(function (Builder $categories) use ($userId): void {
                $categories->where('user_id', $userId)->orWhere('origin', 'system');
            });
        }

        return $query->where('user_id', $userId);
    }

    private function clearLiveData(int $userId): void
    {
        DB::table('notification_projection_facts')->where('user_id', $userId)->delete();
        DB::table('notification_events')->where('user_id', $userId)->delete();

        foreach (self::MUTATION_TABLES as $table) {
            DB::table($table)->where('user_id', $userId)->delete();
        }

        foreach (self::DELETE_ORDER as $table) {
            $query = DB::table($table);
            if ($table === 'budget_category_plans') {
                $query->whereIn('monthly_budget_id', function ($subquery) use ($userId): void {
                    $subquery->select('id')->from('monthly_budgets')->where('user_id', $userId);
                });
            } else {
                $query->where('user_id', $userId);
            }
            $query->delete();
        }
    }

    /** @return array{id: int, created_at: string, record_count: int} */
    private function archiveMetadata(int $archiveId): array
    {
        $archive = DB::table('financial_data_archives')->find($archiveId);

        return [
            'id' => (int) $archive->id,
            'created_at' => (string) $archive->created_at,
            'record_count' => (int) $archive->record_count,
        ];
    }
}
