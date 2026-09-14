<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Data\Transfers\CreateTransferData;
use App\Data\Transfers\TransferFilterData;
use App\Data\Transfers\UpdateTransferData;
use App\Enums\FinancialAccounts\AccountStatus;
use App\Enums\Transfers\TransferStatus;
use App\Exceptions\Transfers\TransferStateException;
use App\Http\Resources\Transfers\TransferResource;
use App\Models\FinancialAccount;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TransferService
{
    /** @return array{transfer: Transfer, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    public function create(User $user, CreateTransferData $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $data, $idempotencyKey): array {
            $fingerprint = $this->fingerprint('create', [
                'source_financial_account_id' => $data->sourceFinancialAccountId,
                'destination_financial_account_id' => $data->destinationFinancialAccountId,
                'amount_centavos' => $data->amountCentavos,
                'transfer_date' => $data->transferDate,
                'status' => $data->status?->value,
                'description' => $data->description,
                'notes' => $data->notes,
            ]);

            $result = app(TransferIdempotencyService::class)->execute(
                $user->id,
                $idempotencyKey,
                'create',
                $fingerprint,
                function () use ($user, $data): array {
                    $source = $this->ownedAccount($user, $data->sourceFinancialAccountId, true, 'source_financial_account_id');
                    $destination = $this->ownedAccount($user, $data->destinationFinancialAccountId, true, 'destination_financial_account_id');
                    if ((int) $source->id === (int) $destination->id) {
                        throw TransferStateException::sameSide();
                    }

                    $future = TransferDateRange::isFuture($data->transferDate);
                    $status = $data->status ?? ($future ? TransferStatus::Pending : TransferStatus::Effective);
                    if ($future && $status === TransferStatus::Effective) {
                        throw TransferStateException::effectiveFutureDate();
                    }

                    $transfer = Transfer::query()->create([
                        'user_id' => $user->id,
                        'source_financial_account_id' => $source->id,
                        'destination_financial_account_id' => $destination->id,
                        'status' => $status,
                        'description' => $data->description,
                        'notes' => $data->notes,
                        'amount_centavos' => $data->amountCentavos,
                        'currency_code' => 'BRL',
                        'transfer_date' => $data->transferDate,
                        'removed_at' => null,
                    ]);

                    $reconciler = app(TransferBalanceReconciler::class);
                    $reconciler->reconcile(null, $transfer, true);

                    return ['transfer_id' => $transfer->id, 'status' => 201];
                },
            );

            return $this->completeMutation($user, $idempotencyKey, $result);
        });
    }

    public function findOwned(User $user, int $transferId): Transfer
    {
        $transfer = Transfer::query()
            ->with(['sourceAccount', 'destinationAccount'])
            ->where('user_id', $user->id)
            ->find($transferId);

        if (! $transfer instanceof Transfer) {
            throw new NotFoundHttpException('Transfer not found or not accessible to the signed-in user.');
        }

        return $transfer;
    }

    /** @return LengthAwarePaginator<int, Transfer> */
    public function list(User $user, TransferFilterData $filters): LengthAwarePaginator
    {
        if ($filters->from !== null && $filters->to !== null && $filters->from > $filters->to) {
            throw ValidationException::withMessages(['to' => ['End date must be on or after start date.']]);
        }
        if ($filters->sourceFinancialAccountId !== null) {
            $this->ownedAccount($user, $filters->sourceFinancialAccountId, false, 'source_financial_account_id');
        }
        if ($filters->destinationFinancialAccountId !== null) {
            $this->ownedAccount($user, $filters->destinationFinancialAccountId, false, 'destination_financial_account_id');
        }

        $query = Transfer::query()->with(['sourceAccount', 'destinationAccount'])->where('user_id', $user->id);
        $filters->view === 'removed' ? $query->removed() : $query->active();
        $query->when($filters->from !== null, fn ($builder) => $builder->whereDate('transfer_date', '>=', $filters->from))
            ->when($filters->to !== null, fn ($builder) => $builder->whereDate('transfer_date', '<=', $filters->to))
            ->when($filters->sourceFinancialAccountId !== null, fn ($builder) => $builder->where('source_financial_account_id', $filters->sourceFinancialAccountId))
            ->when($filters->destinationFinancialAccountId !== null, fn ($builder) => $builder->where('destination_financial_account_id', $filters->destinationFinancialAccountId))
            ->when($filters->status !== null, fn ($builder) => $builder->where('status', $filters->status));
        if ($filters->search !== null && $filters->search !== '') {
            $query->where('search_text', 'like', '%'.TransferTextNormalizer::normalize($filters->search).'%');
        }

        return $query->orderByDesc('transfer_date')->orderByDesc('id')->paginate($filters->perPage, ['*'], 'page', $filters->page);
    }

    /** @return array{transfer: Transfer, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    public function update(User $user, Transfer $transfer, UpdateTransferData $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $transfer, $data, $idempotencyKey): array {
            $locked = Transfer::query()->where('user_id', $user->id)->lockForUpdate()->find($transfer->id);
            if (! $locked instanceof Transfer) {
                throw new NotFoundHttpException('Transfer not found or not accessible to the signed-in user.');
            }

            $fingerprint = $this->fingerprint('update:'.$locked->id, $this->serializableChanges($data->changes));

            $result = app(TransferIdempotencyService::class)->execute($user->id, $idempotencyKey, 'update', $fingerprint, function () use ($user, $locked, $data): array {
                if ($locked->removed_at !== null) {
                    throw TransferStateException::editRequiresRestore();
                }

                $before = clone $locked;
                $sourceId = $data->has('source_financial_account_id') ? (int) $data->changes['source_financial_account_id'] : (int) $locked->source_financial_account_id;
                $destinationId = $data->has('destination_financial_account_id') ? (int) $data->changes['destination_financial_account_id'] : (int) $locked->destination_financial_account_id;
                $source = $data->has('source_financial_account_id') ? $this->ownedAccount($user, $sourceId, true, 'source_financial_account_id') : null;
                $destination = $data->has('destination_financial_account_id') ? $this->ownedAccount($user, $destinationId, true, 'destination_financial_account_id') : null;

                if ($sourceId === $destinationId) {
                    throw TransferStateException::sameSide();
                }

                $date = $data->has('transfer_date') ? (string) $data->changes['transfer_date'] : $locked->transfer_date->toDateString();
                $requestedStatus = $data->has('status') ? $data->changes['status'] : null;
                if ($requestedStatus instanceof TransferStatus
                    && $requestedStatus === TransferStatus::Effective
                    && $locked->status === TransferStatus::Pending
                    && TransferDateRange::isFuture($date)) {
                    throw TransferStateException::effectiveFutureDate();
                }

                $locked->source_financial_account_id = $sourceId;
                $locked->destination_financial_account_id = $destinationId;
                $locked->transfer_date = $date;
                if ($data->has('amount_centavos')) {
                    $locked->amount_centavos = (int) $data->changes['amount_centavos'];
                }
                if ($requestedStatus instanceof TransferStatus) {
                    $locked->status = $requestedStatus;
                }
                if ($data->has('description')) {
                    $locked->description = $data->changes['description'];
                }
                if ($data->has('notes')) {
                    $locked->notes = $data->changes['notes'];
                }
                $locked->save();

                app(TransferBalanceReconciler::class)->reconcile($before, $locked, true);

                $meta = [];
                if ($locked->status === TransferStatus::Effective && TransferDateRange::isFuture($locked->transfer_date)) {
                    $meta['notice'] = [
                        'code' => 'effective_future_date',
                        'message' => 'This future-dated transfer remains effective and still affects both account balances.',
                    ];
                }

                return ['transfer_id' => $locked->id, 'status' => 200, 'meta' => $meta];
            });

            return $this->completeMutation($user, $idempotencyKey, $result);
        });
    }

    /** @return array{transfer: Transfer, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    public function remove(User $user, Transfer $transfer, string $idempotencyKey): array
    {
        return $this->changeLifecycle($user, $transfer, 'remove', $idempotencyKey, function (Transfer $locked): array {
            if ($locked->removed_at !== null) {
                throw TransferStateException::alreadyRemoved();
            }

            $before = clone $locked;
            $locked->removed_at = now();
            $locked->save();
            app(TransferBalanceReconciler::class)->reconcile($before, $locked);

            return ['transfer_id' => $locked->id, 'status' => 200];
        });
    }

    /** @return array{transfer: Transfer, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    public function restore(User $user, Transfer $transfer, ?TransferStatus $requestedStatus, string $idempotencyKey): array
    {
        return $this->changeLifecycle($user, $transfer, 'restore:'.($requestedStatus?->value ?? 'default'), $idempotencyKey, function (Transfer $locked) use ($requestedStatus): array {
            if ($locked->removed_at === null) {
                throw TransferStateException::alreadyActive();
            }

            $status = $requestedStatus ?? (TransferDateRange::isFuture($locked->transfer_date) ? TransferStatus::Pending : TransferStatus::Effective);
            if (TransferDateRange::isFuture($locked->transfer_date) && $status === TransferStatus::Effective) {
                throw TransferStateException::effectiveFutureDate();
            }

            $before = clone $locked;
            $locked->removed_at = null;
            $locked->status = $status;
            $locked->save();
            app(TransferBalanceReconciler::class)->reconcile($before, $locked);

            return ['transfer_id' => $locked->id, 'status' => 200];
        });
    }

    /** @param callable(Transfer): array{transfer_id: int, status: int, meta?: array<string, mixed>} $operation
     * @return array{transfer: Transfer, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool} */
    private function changeLifecycle(User $user, Transfer $transfer, string $action, string $idempotencyKey, callable $operation): array
    {
        return DB::transaction(function () use ($user, $transfer, $action, $idempotencyKey, $operation): array {
            $locked = Transfer::query()->where('user_id', $user->id)->lockForUpdate()->find($transfer->id);
            if (! $locked instanceof Transfer) {
                throw new NotFoundHttpException('Transfer not found or not accessible to the signed-in user.');
            }

            $result = app(TransferIdempotencyService::class)->execute(
                $user->id,
                $idempotencyKey,
                $action,
                $this->fingerprint($action.':'.$locked->id, []),
                fn (): array => $operation($locked),
            );

            return $this->completeMutation($user, $idempotencyKey, $result);
        });
    }

    private function ownedAccount(User $user, int $accountId, bool $mustBeActive, string $field = 'source_financial_account_id'): FinancialAccount
    {
        $account = FinancialAccount::query()->where('user_id', $user->id)->find($accountId);
        if (! $account instanceof FinancialAccount) {
            throw new NotFoundHttpException('Account not found or not accessible to the signed-in user.');
        }
        if ($mustBeActive && $account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages([$field => ['Archived accounts cannot be selected.']]);
        }

        return $account;
    }

    /**
     * @param  array{transfer_id: int, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool}  $result
     * @return array{transfer: Transfer, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool}
     */
    private function completeMutation(User $user, string $idempotencyKey, array $result): array
    {
        $transfer = $this->findOwned($user, $result['transfer_id']);
        $response = $result['response'];

        if (! $result['replayed']) {
            $response = ['data' => (new TransferResource($transfer))->resolve()];
            if ($result['meta'] !== []) {
                $response['meta'] = $result['meta'];
            }
            app(TransferIdempotencyService::class)->storeResponse($user->id, $idempotencyKey, $response);
        }

        return [
            'transfer' => $transfer,
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
