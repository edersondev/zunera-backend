<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\Data\Transactions\CreateTransactionData;
use App\Data\Transactions\TransactionFilterData;
use App\Data\Transactions\TransactionResponseData;
use App\Data\Transactions\UpdateTransactionData;
use App\Enums\Categories\CategoryStatus;
use App\Enums\FinancialAccounts\AccountStatus;
use App\Enums\Transactions\TransactionStatus;
use App\Exceptions\Transactions\TransactionStateException;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TransactionService
{
    /** @return array{transaction: Transaction, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    public function create(User $user, CreateTransactionData $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $data, $idempotencyKey): array {
            $fingerprint = $this->fingerprint('create', [
                'financial_account_id' => $data->financialAccountId,
                'category_id' => $data->categoryId,
                'type' => $data->type->value,
                'description' => $data->description,
                'notes' => $data->notes,
                'amount_centavos' => $data->amountCentavos,
                'transaction_date' => $data->transactionDate,
                'status' => $data->status?->value,
            ]);

            $result = app(TransactionIdempotencyService::class)->execute(
                $user->id,
                $idempotencyKey,
                $fingerprint,
                function () use ($user, $data): array {
                    $account = $this->ownedAccount($user, $data->financialAccountId, true);
                    $category = $this->availableCategory($user, $data->categoryId, true);
                    if ($category->classification->value !== $data->type->value) {
                        throw ValidationException::withMessages(['category_id' => ['Category type must match transaction type.']]);
                    }

                    $status = $data->status ?? (TransactionDateRange::isFuture($data->transactionDate) ? TransactionStatus::Pending : TransactionStatus::Effective);
                    if (TransactionDateRange::isFuture($data->transactionDate) && $status === TransactionStatus::Effective) {
                        throw ValidationException::withMessages(['status' => ['Future-dated transactions must start as pending.']]);
                    }

                    $transaction = Transaction::query()->create([
                        'user_id' => $user->id,
                        'financial_account_id' => $account->id,
                        'category_id' => $category->id,
                        'type' => $data->type,
                        'status' => $status,
                        'description' => $data->description,
                        'notes' => $data->notes,
                        'amount_centavos' => $data->amountCentavos,
                        'currency_code' => 'BRL',
                        'transaction_date' => $data->transactionDate,
                        'removed_at' => null,
                    ]);
                    $account->has_financial_movements = true;
                    $account->save();
                    $category->has_financial_transactions = true;
                    $category->save();
                    app(TransactionBalanceReconciler::class)->reconcile(null, $transaction);

                    return ['transaction_id' => $transaction->id, 'status' => 201];
                },
            );

            return $this->completeMutation($user, $idempotencyKey, $result);
        });
    }

    public function findOwned(User $user, int $transactionId): Transaction
    {
        $transaction = Transaction::query()
            ->with(['financialAccount', 'category'])
            ->where('user_id', $user->id)
            ->find($transactionId);

        if (! $transaction instanceof Transaction) {
            throw new NotFoundHttpException('Transaction not found or not accessible to the signed-in user.');
        }

        return $transaction;
    }

    /** @return LengthAwarePaginator<int, Transaction> */
    public function list(User $user, TransactionFilterData $filters): LengthAwarePaginator
    {
        if ($filters->from !== null && $filters->to !== null && $filters->from > $filters->to) {
            throw ValidationException::withMessages(['to' => ['End date must be on or after start date.']]);
        }
        if ($filters->financialAccountId !== null) {
            $this->ownedAccount($user, $filters->financialAccountId, false);
        }
        if ($filters->categoryId !== null) {
            $this->availableCategory($user, $filters->categoryId, false);
        }

        $query = Transaction::query()->with(['financialAccount', 'category'])->where('user_id', $user->id);
        $filters->view === 'removed' ? $query->removed() : $query->active();
        $query->when($filters->from !== null, fn ($builder) => $builder->whereDate('transaction_date', '>=', $filters->from))
            ->when($filters->to !== null, fn ($builder) => $builder->whereDate('transaction_date', '<=', $filters->to))
            ->when($filters->type !== null, fn ($builder) => $builder->where('type', $filters->type))
            ->when($filters->financialAccountId !== null, fn ($builder) => $builder->where('financial_account_id', $filters->financialAccountId))
            ->when($filters->categoryId !== null, fn ($builder) => $builder->where('category_id', $filters->categoryId))
            ->when($filters->status !== null, fn ($builder) => $builder->where('status', $filters->status));
        if ($filters->search !== null && $filters->search !== '') {
            $query->where('search_text', 'like', '%'.TransactionTextNormalizer::normalize($filters->search).'%');
        }

        return $query->orderByDesc('transaction_date')->orderByDesc('id')->paginate($filters->perPage, ['*'], 'page', $filters->page);
    }

    /** @return array{transaction: Transaction, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    public function update(User $user, Transaction $transaction, UpdateTransactionData $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $transaction, $data, $idempotencyKey): array {
            $locked = Transaction::query()->where('user_id', $user->id)->lockForUpdate()->find($transaction->id);
            if (! $locked instanceof Transaction) {
                throw new NotFoundHttpException('Transaction not found or not accessible to the signed-in user.');
            }
            $fingerprint = $this->fingerprint('update:'.$locked->id, $this->serializableChanges($data->changes));
            $result = app(TransactionIdempotencyService::class)->execute($user->id, $idempotencyKey, $fingerprint, function () use ($user, $locked, $data): array {
                if ($locked->removed_at !== null) {
                    throw TransactionStateException::editRequiresRestore();
                }
                $before = clone $locked;
                $accountId = $data->has('financial_account_id') ? (int) $data->changes['financial_account_id'] : (int) $locked->financial_account_id;
                $categoryId = $data->has('category_id') ? (int) $data->changes['category_id'] : (int) $locked->category_id;
                $account = $data->has('financial_account_id') ? $this->ownedAccount($user, $accountId, true) : null;
                $category = $data->has('category_id') ? $this->availableCategory($user, $categoryId, true) : null;
                $type = $data->has('type') ? $data->changes['type'] : $locked->type;
                $resolvedCategory = $category ?? $locked->category()->firstOrFail();
                if ($resolvedCategory->classification->value !== $type->value) {
                    throw ValidationException::withMessages(['category_id' => ['Category type must match transaction type.']]);
                }

                // A generated occurrence keeps its recurrence source link: ordinary edits
                // correct the movement itself and never detach it from its rule or date.
                $recurringTransactionId = $locked->recurring_transaction_id;
                $recurrenceScheduledDate = $locked->recurrence_scheduled_date?->toDateString();

                foreach ($data->changes as $key => $value) {
                    $locked->{$key} = $value;
                }
                $locked->financial_account_id = $accountId;
                $locked->category_id = $categoryId;
                $locked->recurring_transaction_id = $recurringTransactionId;
                $locked->recurrence_scheduled_date = $recurrenceScheduledDate;
                $locked->save();
                if ($account instanceof FinancialAccount) {
                    $account->has_financial_movements = true;
                    $account->save();
                }
                if ($category instanceof Category) {
                    $category->has_financial_transactions = true;
                    $category->save();
                }
                app(TransactionBalanceReconciler::class)->reconcile($before, $locked);
                $meta = [];
                if ($locked->status === TransactionStatus::Effective && TransactionDateRange::isFuture($locked->transaction_date)) {
                    $meta['notice'] = ['code' => 'effective_future_date', 'message' => 'This future-dated transaction remains effective and still affects the account balance.'];
                }

                return ['transaction_id' => $locked->id, 'status' => 200, 'meta' => $meta];
            });

            return $this->completeMutation($user, $idempotencyKey, $result);
        });
    }

    /** @return array{transaction: Transaction, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    public function remove(User $user, Transaction $transaction, string $idempotencyKey): array
    {
        return $this->changeLifecycle($user, $transaction, 'remove', $idempotencyKey, function (Transaction $locked): array {
            if ($locked->removed_at !== null) {
                throw TransactionStateException::alreadyRemoved();
            }
            $before = clone $locked;
            $locked->removed_at = now();
            $locked->save();
            app(TransactionBalanceReconciler::class)->reconcile($before, $locked);

            return ['transaction_id' => $locked->id, 'status' => 200];
        });
    }

    /** @return array{transaction: Transaction, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    public function restore(User $user, Transaction $transaction, ?TransactionStatus $requestedStatus, string $idempotencyKey): array
    {
        return $this->changeLifecycle($user, $transaction, 'restore:'.($requestedStatus?->value ?? 'default'), $idempotencyKey, function (Transaction $locked) use ($requestedStatus): array {
            if ($locked->removed_at === null) {
                throw TransactionStateException::alreadyActive();
            }
            $status = $requestedStatus ?? (TransactionDateRange::isFuture($locked->transaction_date) ? TransactionStatus::Pending : TransactionStatus::Effective);
            if (TransactionDateRange::isFuture($locked->transaction_date) && $status === TransactionStatus::Effective) {
                throw ValidationException::withMessages(['status' => ['Future-dated transactions must be restored as pending.']]);
            }
            $before = clone $locked;
            $locked->removed_at = null;
            $locked->status = $status;
            $locked->save();
            app(TransactionBalanceReconciler::class)->reconcile($before, $locked);

            return ['transaction_id' => $locked->id, 'status' => 200];
        });
    }

    /** @param callable(Transaction): array{transaction_id: int, status: int, meta?: array<string, mixed>} $operation
     * @return array{transaction: Transaction, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    private function changeLifecycle(User $user, Transaction $transaction, string $action, string $idempotencyKey, callable $operation): array
    {
        return DB::transaction(function () use ($user, $transaction, $action, $idempotencyKey, $operation): array {
            $locked = Transaction::query()->where('user_id', $user->id)->lockForUpdate()->find($transaction->id);
            if (! $locked instanceof Transaction) {
                throw new NotFoundHttpException('Transaction not found or not accessible to the signed-in user.');
            }
            $result = app(TransactionIdempotencyService::class)->execute($user->id, $idempotencyKey, $this->fingerprint($action.':'.$locked->id, []), fn (): array => $operation($locked));

            return $this->completeMutation($user, $idempotencyKey, $result);
        });
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

    private function availableCategory(User $user, int $categoryId, bool $mustBeActive): Category
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

        return $category;
    }

    /**
     * @param  array{transaction_id: int, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool}  $result
     * @return array{transaction: Transaction, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool}
     */
    private function completeMutation(User $user, string $idempotencyKey, array $result): array
    {
        $transaction = $this->findOwned($user, $result['transaction_id']);
        $response = $result['response'];

        if (! $result['replayed']) {
            $response = ['data' => TransactionResponseData::from($transaction)];
            if ($result['meta'] !== []) {
                $response['meta'] = $result['meta'];
            }
            app(TransactionIdempotencyService::class)->storeResponse($user->id, $idempotencyKey, $response);
        }

        return [
            'transaction' => $transaction,
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
