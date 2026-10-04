<?php

declare(strict_types=1);

namespace App\Services\AccountData;

use App\Exceptions\AccountData\ArchiveRestoreException;
use App\Exceptions\ProfilePasswordThrottledException;
use App\Models\User;
use App\Services\Authentication\AuthenticationAudit;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
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

    public function __construct(
        private readonly AuthenticationAudit $audit,
        private readonly ArchiveRecordRestorer $restorer,
        private readonly ArchiveNotificationProjector $notifications,
    ) {}

    /** @return array{id: int, created_at: string, record_count: int} */
    public function archive(User $user): array
    {
        $result = DB::transaction(function () use ($user): array {
            $this->lockUser($user);
            $archiveId = $this->snapshotLiveData((int) $user->id);
            $this->clearLiveData((int) $user->id);

            return $this->archiveMetadata($archiveId);
        }, 3);

        $this->audit->record('financial_data_archived', ['user_id' => $user->id, 'archive_id' => $result['id']]);

        return $result;
    }

    /** @return array{restored_archive_id: int, restored_record_count: int, previous_archive_id: ?int} */
    public function restore(User $user, int $archiveId): array
    {
        $result = DB::transaction(function () use ($user, $archiveId): array {
            $this->lockUser($user);
            $archive = DB::table('financial_data_archives')
                ->where('user_id', $user->id)->lockForUpdate()->find($archiveId);
            if ($archive === null) {
                throw new NotFoundHttpException('Archive not found.');
            }

            $this->restorer->verify($archiveId, (int) $archive->record_count);
            $previousArchiveId = $this->hasLiveData((int) $user->id)
                ? $this->snapshotLiveData((int) $user->id) : null;
            $this->clearLiveData((int) $user->id);
            try {
                $restored = $this->restorer->restore((int) $user->id, $archiveId);
                $this->notifications->capture((int) $user->id, $restored);
            } catch (QueryException $exception) {
                throw new ArchiveRestoreException('This archive cannot be restored safely. Current data was left unchanged.', previous: $exception);
            }

            return [
                'restored_archive_id' => $archiveId,
                'restored_record_count' => (int) $archive->record_count,
                'previous_archive_id' => $previousArchiveId,
            ];
        }, 3);

        $this->audit->record('financial_data_restored', [
            'user_id' => $user->id,
            'archive_id' => $archiveId,
            'previous_archive_id' => $result['previous_archive_id'],
        ]);

        return $result;
    }

    public function deleteAll(User $user, string $currentPassword): void
    {
        $key = 'auth:profile-password:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new ProfilePasswordThrottledException(RateLimiter::availableIn($key));
        }

        $passwordValid = DB::transaction(function () use ($user, $currentPassword): bool {
            $locked = $this->lockUser($user);
            if (! Hash::check($currentPassword, $locked->password)) {
                return false;
            }

            $this->clearLiveData((int) $user->id);
            DB::table('financial_data_archives')->where('user_id', $user->id)->delete();

            return true;
        }, 3);

        if (! $passwordValid) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages([
                'current_password' => [__('auth.current_password_invalid')],
            ]);
        }

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

    private function hasLiveData(int $userId): bool
    {
        foreach (self::RECORD_TYPES as $table) {
            if ($table === 'categories') {
                if (DB::table($table)->where('user_id', $userId)->exists()) {
                    return true;
                }
            } elseif ($this->ownedRows($table, $userId)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function snapshotLiveData(int $userId): int
    {
        $archiveId = (int) DB::table('financial_data_archives')->insertGetId([
            'user_id' => $userId,
            'record_count' => 0,
            'created_at' => now(),
        ]);
        $recordCount = 0;

        foreach (self::RECORD_TYPES as $table) {
            $this->ownedRows($table, $userId)->orderBy('id')
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

        return $archiveId;
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
