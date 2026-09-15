<?php

declare(strict_types=1);

namespace App\Services\RecurringTransactions;

use App\Data\RecurringTransactions\CreateRecurringTransactionData;
use App\Data\RecurringTransactions\RecurringTransactionFilterData;
use App\Data\RecurringTransactions\UpdateRecurringTransactionData;
use App\Enums\Categories\CategoryStatus;
use App\Enums\FinancialAccounts\AccountStatus;
use App\Enums\RecurringTransactions\RecurrencePausedReason;
use App\Enums\RecurringTransactions\RecurrenceState;
use App\Enums\Transactions\TransactionType;
use App\Exceptions\RecurringTransactions\RecurrenceStateException;
use App\Http\Resources\RecurringTransactions\RecurringTransactionResource;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RecurringTransactionService
{
    public function __construct(
        private readonly RecurringScheduleCalculator $calculator,
        private readonly RecurringIdempotencyService $idempotency,
    ) {}

    /** @return array{rule: RecurringTransaction, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    public function create(User $user, CreateRecurringTransactionData $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $data, $idempotencyKey): array {
            $fingerprint = $this->fingerprint('create', [
                'financial_account_id' => $data->financialAccountId,
                'category_id' => $data->categoryId,
                'type' => $data->type->value,
                'amount_centavos' => $data->amountCentavos,
                'description' => $data->description,
                'notes' => $data->notes,
                'frequency' => $data->frequency->value,
                'start_date' => $data->startDate,
                'end_date' => $data->endDate,
            ]);

            $result = $this->idempotency->execute($user->id, $idempotencyKey, 'create', $fingerprint, function () use ($user, $data): array {
                $account = $this->ownedAccount($user, $data->financialAccountId, true);
                $category = $this->availableCategory($user, $data->categoryId, true, $data->type);
                $eligibilityStart = max($data->startDate, RecurringDateRange::businessDate());

                $rule = RecurringTransaction::query()->create([
                    'user_id' => $user->id,
                    'financial_account_id' => $account->id,
                    'category_id' => $category->id,
                    'type' => $data->type,
                    'amount_centavos' => $data->amountCentavos,
                    'currency_code' => 'BRL',
                    'description' => $data->description,
                    'notes' => $data->notes,
                    'frequency' => $data->frequency,
                    'start_date' => $data->startDate,
                    'end_date' => $data->endDate,
                    'state' => RecurrenceState::Active,
                    'paused_reason' => null,
                    'eligibility_starts_on' => $eligibilityStart,
                    'schedule_cursor' => $eligibilityStart,
                    'ended_at' => null,
                ]);

                return ['recurring_transaction_id' => $rule->id, 'status' => 201];
            });

            return $this->completeMutation($user, $idempotencyKey, $result);
        });
    }

    public function findOwned(User $user, int $recurringTransactionId): RecurringTransaction
    {
        $rule = RecurringTransaction::query()
            ->with(['financialAccount', 'category'])
            ->where('user_id', $user->id)
            ->find($recurringTransactionId);

        if (! $rule instanceof RecurringTransaction) {
            throw new NotFoundHttpException('Recurring transaction not found or not accessible to the signed-in user.');
        }

        $this->decorate(new Collection([$rule]));

        return $rule;
    }

    /** @return LengthAwarePaginator<int, RecurringTransaction> */
    public function list(User $user, RecurringTransactionFilterData $filters): LengthAwarePaginator
    {
        if ($filters->financialAccountId !== null) {
            $this->ownedAccount($user, $filters->financialAccountId, false);
        }
        if ($filters->categoryId !== null) {
            $this->availableCategory($user, $filters->categoryId, false, null);
        }

        $rules = RecurringTransaction::query()
            ->where('user_id', $user->id)
            ->when($filters->type !== null, fn ($query) => $query->where('type', $filters->type))
            ->when($filters->financialAccountId !== null, fn ($query) => $query->where('financial_account_id', $filters->financialAccountId))
            ->when($filters->categoryId !== null, fn ($query) => $query->where('category_id', $filters->categoryId))
            ->when($filters->frequency !== null, fn ($query) => $query->where('frequency', $filters->frequency))
            ->when($filters->state !== null, fn ($query) => $query->where('state', $filters->state))
            ->orderBy('id')
            ->get();

        $this->decorate($rules);

        $ordered = $rules->sortBy(static function (RecurringTransaction $rule): string {
            $next = $rule->getAttribute('next_expected_occurrence');

            return is_string($next) && $next !== ''
                ? '0|'.$next
                : '1|'.str_pad((string) $rule->id, 20, '0', STR_PAD_LEFT);
        })->values();

        $page = max(1, $filters->page);
        $slice = $ordered->slice(($page - 1) * $filters->perPage, $filters->perPage)->values();
        $slice->load(['financialAccount', 'category']);

        return new LengthAwarePaginator($slice, $ordered->count(), $filters->perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]);
    }

    /** @return LengthAwarePaginator<int, Transaction> */
    public function listOccurrences(User $user, RecurringTransaction $rule, int $page, int $perPage): LengthAwarePaginator
    {
        $owned = $this->findOwned($user, (int) $rule->id);

        return $owned->generatedOccurrences()
            ->orderByDesc('recurrence_scheduled_date')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', max(1, $page));
    }

    /** @return array{rule: RecurringTransaction, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    public function update(User $user, RecurringTransaction $rule, UpdateRecurringTransactionData $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $rule, $data, $idempotencyKey): array {
            $locked = RecurringTransaction::query()->where('user_id', $user->id)->lockForUpdate()->find($rule->id);
            if (! $locked instanceof RecurringTransaction) {
                throw new NotFoundHttpException('Recurring transaction not found or not accessible to the signed-in user.');
            }

            $fingerprint = $this->fingerprint('update:'.$locked->id, $this->serializableChanges($data->changes));

            $result = $this->idempotency->execute($user->id, $idempotencyKey, 'update', $fingerprint, function () use ($user, $locked, $data): array {
                if ($locked->isEnded()) {
                    throw RecurrenceStateException::ended();
                }
                if ($locked->isPaused() && $locked->paused_reason !== RecurrencePausedReason::AssociationArchived) {
                    throw RecurrenceStateException::notActive();
                }

                $type = $data->has('type') ? $data->changes['type'] : $locked->type;
                $accountId = $data->has('financial_account_id') ? (int) $data->changes['financial_account_id'] : (int) $locked->financial_account_id;
                $categoryId = $data->has('category_id') ? (int) $data->changes['category_id'] : (int) $locked->category_id;

                if ($data->has('financial_account_id')) {
                    $this->ownedAccount($user, $accountId, true);
                }
                if ($data->has('category_id') || $data->has('type')) {
                    $this->availableCategory($user, $categoryId, true, $type instanceof TransactionType ? $type : null);
                }

                $startDate = $data->has('start_date') ? (string) $data->changes['start_date'] : $locked->start_date->toDateString();
                $endDate = $data->has('end_date') ? $data->changes['end_date'] : $locked->endDateOrNull();
                if ($endDate !== null && (string) $endDate < $startDate) {
                    throw ValidationException::withMessages(['end_date' => ['End date must be on or after the start date.']]);
                }

                foreach ($data->changes as $key => $value) {
                    $locked->{$key} = $value;
                }
                $locked->financial_account_id = $accountId;
                $locked->category_id = $categoryId;
                $locked->start_date = $startDate;
                $locked->end_date = $endDate;
                if ($data->has('start_date')) {
                    // A start-date edit only changes future scheduling. Reset the
                    // watermark to the current business date (or the new future
                    // start) so an earlier edit cannot backfill elapsed dates and
                    // a later edit waits for its new anchor.
                    $scheduleAnchor = max($startDate, RecurringDateRange::businessDate());
                    $locked->eligibility_starts_on = $scheduleAnchor;
                    $locked->schedule_cursor = $scheduleAnchor;
                }
                $locked->save();

                return ['recurring_transaction_id' => $locked->id, 'status' => 200];
            });

            return $this->completeMutation($user, $idempotencyKey, $result);
        });
    }

    /** @return array{rule: RecurringTransaction, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    public function pause(User $user, RecurringTransaction $rule, string $idempotencyKey): array
    {
        return $this->changeLifecycle($user, $rule, 'pause', $idempotencyKey, function (RecurringTransaction $locked): array {
            if ($locked->isEnded()) {
                throw RecurrenceStateException::ended();
            }
            if (! $locked->isActive()) {
                throw RecurrenceStateException::notActive();
            }

            $locked->state = RecurrenceState::Paused;
            $locked->paused_reason = RecurrencePausedReason::User;
            $locked->save();

            return ['recurring_transaction_id' => $locked->id, 'status' => 200];
        });
    }

    /** @return array{rule: RecurringTransaction, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    public function resume(User $user, RecurringTransaction $rule, string $idempotencyKey): array
    {
        return $this->changeLifecycle($user, $rule, 'resume', $idempotencyKey, function (RecurringTransaction $locked): array {
            if ($locked->isEnded()) {
                throw RecurrenceStateException::ended();
            }
            if (! $locked->isPaused()) {
                throw RecurrenceStateException::notPaused();
            }

            $account = $locked->financialAccount()->first();
            $category = $locked->category()->first();
            $associationAvailable = $account instanceof FinancialAccount
                && $account->status === AccountStatus::Active
                && $category instanceof Category
                && $category->status === CategoryStatus::Active
                && $category->classification->value === $locked->type->value;

            if (! $associationAvailable) {
                throw RecurrenceStateException::associationUnavailable();
            }

            $businessDate = RecurringDateRange::businessDate();
            if ($this->calculator->firstEligibleOnOrAfter($locked, $businessDate) === null) {
                throw RecurrenceStateException::associationUnavailable('end_date');
            }

            $locked->state = RecurrenceState::Active;
            $locked->paused_reason = null;
            $locked->schedule_cursor = max($locked->schedule_cursor->toDateString(), $businessDate);
            $locked->save();

            return ['recurring_transaction_id' => $locked->id, 'status' => 200];
        });
    }

    /** @return array{rule: RecurringTransaction, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    public function end(User $user, RecurringTransaction $rule, string $idempotencyKey): array
    {
        return $this->changeLifecycle($user, $rule, 'end', $idempotencyKey, function (RecurringTransaction $locked): array {
            if ($locked->isEnded()) {
                throw RecurrenceStateException::ended();
            }

            $locked->state = RecurrenceState::Ended;
            $locked->paused_reason = null;
            $locked->ended_at = now();
            $locked->save();

            return ['recurring_transaction_id' => $locked->id, 'status' => 200];
        });
    }

    /** Pause every active rule that depends on an account archived by its owner. */
    public function pauseForArchivedAccount(int $accountId): int
    {
        return $this->pauseMatching(fn () => RecurringTransaction::query()->active()->where('financial_account_id', $accountId)->get());
    }

    /** Pause every active rule that depends on a category archived by its owner. */
    public function pauseForArchivedCategory(int $categoryId): int
    {
        return $this->pauseMatching(fn () => RecurringTransaction::query()->active()->where('category_id', $categoryId)->get());
    }

    /** @param callable(): Collection<int, RecurringTransaction> $resolve */
    private function pauseMatching(callable $resolve): int
    {
        $rules = $resolve();
        foreach ($rules as $rule) {
            $rule->state = RecurrenceState::Paused;
            $rule->paused_reason = RecurrencePausedReason::AssociationArchived;
            $rule->save();
        }

        return $rules->count();
    }

    /** @param callable(RecurringTransaction): array{recurring_transaction_id: int, status: int, meta?: array<string, mixed>} $operation
     * @return array{rule: RecurringTransaction, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    private function changeLifecycle(User $user, RecurringTransaction $rule, string $action, string $idempotencyKey, callable $operation): array
    {
        return DB::transaction(function () use ($user, $rule, $action, $idempotencyKey, $operation): array {
            $locked = RecurringTransaction::query()->where('user_id', $user->id)->lockForUpdate()->find($rule->id);
            if (! $locked instanceof RecurringTransaction) {
                throw new NotFoundHttpException('Recurring transaction not found or not accessible to the signed-in user.');
            }

            $result = $this->idempotency->execute(
                $user->id,
                $idempotencyKey,
                $action,
                $this->fingerprint($action.':'.$locked->id, []),
                fn (): array => $operation($locked),
            );

            return $this->completeMutation($user, $idempotencyKey, $result);
        });
    }

    /** Adds the derived next-date and occurrence-count projections used by list and detail responses. */
    private function decorate(Collection $rules): void
    {
        if ($rules->isEmpty()) {
            return;
        }

        $ids = $rules->pluck('id')->all();
        $today = RecurringDateRange::businessDate();

        $counts = DB::table('transactions')
            ->whereIn('recurring_transaction_id', $ids)
            ->selectRaw('recurring_transaction_id, count(*) as total')
            ->groupBy('recurring_transaction_id')
            ->pluck('total', 'recurring_transaction_id');

        $upcoming = DB::table('transactions')
            ->whereIn('recurring_transaction_id', $ids)
            ->whereNotNull('recurrence_scheduled_date')
            ->whereDate('recurrence_scheduled_date', '>=', $today)
            ->get(['recurring_transaction_id', 'recurrence_scheduled_date']);

        $generatedByRule = [];
        foreach ($upcoming as $row) {
            $generatedByRule[(int) $row->recurring_transaction_id][$this->asDateString($row->recurrence_scheduled_date)] = true;
        }

        foreach ($rules as $rule) {
            $rule->setAttribute('generated_occurrence_count', (int) ($counts[$rule->id] ?? 0));
            $rule->setAttribute(
                'next_expected_occurrence',
                $this->calculator->nextExpectedOccurrence($rule, $today, $generatedByRule[$rule->id] ?? []),
            );
        }
    }

    /** Raw query builders bypass model casts, so stored date-time values are normalized back to dates. */
    private function asDateString(mixed $value): string
    {
        return $value === null ? '' : substr((string) $value, 0, 10);
    }

    private function ownedAccount(User $user, int $accountId, bool $mustBeActive): FinancialAccount
    {
        $account = FinancialAccount::query()->where('user_id', $user->id)->find($accountId);
        if (! $account instanceof FinancialAccount) {
            throw new NotFoundHttpException('Account not found or not accessible to the signed-in user.');
        }
        if ($mustBeActive && $account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages(['financial_account_id' => ['Archived accounts cannot be selected.']]);
        }

        return $account;
    }

    private function availableCategory(User $user, int $categoryId, bool $mustBeActive, ?TransactionType $type): Category
    {
        $category = Category::query()->where('id', $categoryId)->where(function ($query) use ($user): void {
            $query->where('origin', 'system')->orWhere(function ($personal) use ($user): void {
                $personal->where('origin', 'personal')->where('user_id', $user->id);
            });
        })->first();
        if (! $category instanceof Category) {
            throw new NotFoundHttpException('Category not found or not accessible to the signed-in user.');
        }
        if ($mustBeActive && $category->status !== CategoryStatus::Active) {
            throw ValidationException::withMessages(['category_id' => ['Archived categories cannot be selected.']]);
        }
        if ($type !== null && $category->classification->value !== $type->value) {
            throw ValidationException::withMessages(['category_id' => ['Category type must match recurring transaction type.']]);
        }

        return $category;
    }

    /**
     * @param  array{recurring_transaction_id: int, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool}  $result
     * @return array{rule: RecurringTransaction, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool}
     */
    private function completeMutation(User $user, string $idempotencyKey, array $result): array
    {
        $rule = $this->findOwned($user, $result['recurring_transaction_id']);
        $response = $result['response'];

        if (! $result['replayed']) {
            $response = ['data' => (new RecurringTransactionResource($rule))->resolve()];
            if ($result['meta'] !== []) {
                $response['meta'] = $result['meta'];
            }
            $this->idempotency->storeResponse($user->id, $idempotencyKey, $response);
        }

        return [
            'rule' => $rule,
            'status' => $result['status'],
            'meta' => $result['meta'],
            'response' => $response,
            'replayed' => $result['replayed'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(string $action, array $payload): string
    {
        ksort($payload);

        return hash('sha256', $action.'|'.json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed> */
    private function serializableChanges(array $changes): array
    {
        foreach ($changes as $key => $value) {
            if ($value instanceof \BackedEnum) {
                $changes[$key] = $value->value;
            }
        }

        return $changes;
    }
}
