<?php

declare(strict_types=1);

namespace App\Services\FinancialAccounts;

use App\Data\FinancialAccounts\CreateFinancialAccountData;
use App\Data\FinancialAccounts\UpdateFinancialAccountData;
use App\Enums\FinancialAccounts\AccountStatus;
use App\Exceptions\FinancialAccounts\FinancialAccountNameConflictException;
use App\Exceptions\FinancialAccounts\FinancialAccountStateException;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\RecurringTransactions\RecurringTransactionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FinancialAccountService
{
    /**
     * @return Collection<int, FinancialAccount>
     */
    public function list(User $user, AccountStatus $status): Collection
    {
        return FinancialAccount::query()
            ->where('user_id', $user->id)
            ->where('status', $status)
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @return array{active_account_count: int, active_combined_balance_centavos: int, currency_code: string}
     */
    public function summary(User $user): array
    {
        $summary = FinancialAccount::query()
            ->where('user_id', $user->id)
            ->where('status', AccountStatus::Active)
            ->selectRaw('COUNT(*) as active_account_count, COALESCE(SUM(current_balance_centavos), 0) as active_combined_balance_centavos')
            ->first();

        return [
            'active_account_count' => (int) ($summary->active_account_count ?? 0),
            'active_combined_balance_centavos' => (int) ($summary->active_combined_balance_centavos ?? 0),
            'currency_code' => 'BRL',
        ];
    }

    public function findOwned(User $user, int $accountId): FinancialAccount
    {
        $account = FinancialAccount::query()
            ->where('user_id', $user->id)
            ->find($accountId);

        if (! $account instanceof FinancialAccount) {
            throw new NotFoundHttpException('Account not found or not accessible to the signed-in user.');
        }

        return $account;
    }

    public function create(CreateFinancialAccountData $data): FinancialAccount
    {
        try {
            return DB::transaction(function () use ($data): FinancialAccount {
                $name = trim($data->name);

                return FinancialAccount::query()->create([
                    'user_id' => $data->userId,
                    'name' => $name,
                    'normalized_name' => FinancialAccountNameNormalizer::normalize($name),
                    'account_type' => $data->accountType,
                    'institution_name' => $data->institutionName !== null ? trim($data->institutionName) : null,
                    'color' => FinancialAccountVisualOptions::color($data->color),
                    'icon' => FinancialAccountVisualOptions::icon($data->icon),
                    'initial_balance_centavos' => $data->initialBalanceCentavos,
                    'current_balance_centavos' => $data->initialBalanceCentavos,
                    'currency_code' => 'BRL',
                    'status' => AccountStatus::Active,
                    'archived_at' => null,
                    'has_financial_movements' => false,
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'name' => ['An active account with this name already exists.'],
                ]);
            }

            throw $exception;
        }
    }

    public function update(User $user, FinancialAccount $account, UpdateFinancialAccountData $data): FinancialAccount
    {
        $this->assertOwnedBy($user, $account);

        try {
            return DB::transaction(function () use ($account, $data): FinancialAccount {
                if ($data->has('name')) {
                    $name = trim((string) $data->changes['name']);
                    $normalized = FinancialAccountNameNormalizer::normalize($name);

                    if ($account->status === AccountStatus::Active) {
                        $conflictExists = FinancialAccount::query()
                            ->where('user_id', $account->user_id)
                            ->where('status', AccountStatus::Active)
                            ->where('normalized_name', $normalized)
                            ->where('id', '!=', $account->id)
                            ->exists();

                        if ($conflictExists) {
                            throw FinancialAccountNameConflictException::activeNameConflict();
                        }
                    }

                    $account->name = $name;
                    $account->normalized_name = $normalized;
                }

                if ($data->has('account_type')) {
                    $account->account_type = $data->changes['account_type'];
                }

                if ($data->has('institution_name')) {
                    $institution = $data->changes['institution_name'];
                    $account->institution_name = $institution !== null ? trim((string) $institution) : null;
                }

                if ($data->has('color')) {
                    $account->color = FinancialAccountVisualOptions::color($data->changes['color']);
                }

                if ($data->has('icon')) {
                    $account->icon = FinancialAccountVisualOptions::icon($data->changes['icon']);
                }

                if ($data->has('initial_balance_centavos')) {
                    if ($account->has_financial_movements) {
                        throw FinancialAccountStateException::initialBalanceLocked();
                    }

                    $account->initial_balance_centavos = $data->changes['initial_balance_centavos'];
                    $account->current_balance_centavos = $data->changes['initial_balance_centavos'];
                }

                $account->save();

                return $account;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw FinancialAccountNameConflictException::activeNameConflict();
            }

            throw $exception;
        }
    }

    public function archive(User $user, FinancialAccount $account): FinancialAccount
    {
        $this->assertOwnedBy($user, $account);

        return DB::transaction(function () use ($account): FinancialAccount {
            if ($account->status !== AccountStatus::Active) {
                throw FinancialAccountStateException::alreadyArchived();
            }

            $account->status = AccountStatus::Archived;
            $account->archived_at = now();
            $account->save();

            // Archiving an association pauses its active recurring rules without touching history.
            app(RecurringTransactionService::class)->pauseForArchivedAccount((int) $account->id);

            return $account;
        });
    }

    public function restore(User $user, FinancialAccount $account): FinancialAccount
    {
        $this->assertOwnedBy($user, $account);

        try {
            return DB::transaction(function () use ($account): FinancialAccount {
                if ($account->status !== AccountStatus::Archived) {
                    throw FinancialAccountStateException::alreadyActive();
                }

                $normalized = FinancialAccountNameNormalizer::normalize((string) $account->name);
                $conflictExists = FinancialAccount::query()
                    ->where('user_id', $account->user_id)
                    ->where('status', AccountStatus::Active)
                    ->where('normalized_name', $normalized)
                    ->where('id', '!=', $account->id)
                    ->exists();

                if ($conflictExists) {
                    throw FinancialAccountNameConflictException::activeNameConflict();
                }

                $account->status = AccountStatus::Active;
                $account->archived_at = null;
                $account->save();

                return $account;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw FinancialAccountNameConflictException::activeNameConflict();
            }

            throw $exception;
        }
    }

    private function assertOwnedBy(User $user, FinancialAccount $account): void
    {
        if ((int) $account->user_id !== (int) $user->id) {
            throw new NotFoundHttpException('Account not found or not accessible to the signed-in user.');
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique constraint failed')
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'unique constraint');
    }
}
