<?php

declare(strict_types=1);

namespace App\Services\FinancialHistory;

use App\Data\FinancialHistory\FinancialHistoryFilterData;
use App\Http\Resources\FinancialHistory\FinancialHistoryResource;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Transactions\TransactionTextNormalizer;
use App\Services\Transfers\TransferTextNormalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FinancialHistoryService
{
    /**
     * Mixed income, expense, and transfer projection ordered newest first. One
     * paginated union query keeps same-date ordering and totals exact for 5,000+
     * movements without loading either side twice.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $user, FinancialHistoryFilterData $filters): LengthAwarePaginator
    {
        if ($filters->from !== null && $filters->to !== null && $filters->from > $filters->to) {
            throw ValidationException::withMessages(['to' => ['End date must be on or after start date.']]);
        }
        if ($filters->financialAccountId !== null) {
            $this->ownedAccount($user, $filters->financialAccountId);
        }

        $movements = $this->movementKeys($user, $filters);
        $total = $movements->count();
        $keys = $movements
            ->orderByDesc('movement_date')
            ->orderByDesc('movement_id')
            ->forPage($filters->page, $filters->perPage)
            ->get();

        $entries = $this->entriesFor($user, $keys);

        return new LengthAwarePaginator($entries, $total, $filters->perPage, $filters->page);
    }

    /**
     * Income, expense, and financial-result aggregates derived only from effective
     * income/expense movements. Transfers are a separate movement kind and never
     * contribute to these reporting totals.
     *
     * @return array{income_centavos: int, expense_centavos: int, financial_result_centavos: int, currency_code: string}
     */
    public function totals(User $user): array
    {
        $row = DB::table('transactions')
            ->where('user_id', $user->id)
            ->whereNull('removed_at')
            ->where('status', 'effective')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount_centavos ELSE 0 END), 0) as income_centavos")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount_centavos ELSE 0 END), 0) as expense_centavos")
            ->first();

        $income = (int) ($row->income_centavos ?? 0);
        $expense = (int) ($row->expense_centavos ?? 0);

        return [
            'income_centavos' => $income,
            'expense_centavos' => $expense,
            'financial_result_centavos' => $income - $expense,
            'currency_code' => 'BRL',
        ];
    }

    private function movementKeys(User $user, FinancialHistoryFilterData $filters): Builder
    {
        $transactions = DB::table('transactions')
            ->where('transactions.user_id', $user->id)
            ->selectRaw("'transaction' as entry_source, transactions.id as movement_id, transactions.transaction_date as movement_date");

        $transfers = DB::table('transfers')
            ->where('transfers.user_id', $user->id)
            ->whereNull('transfers.removed_at')
            ->selectRaw("'transfer' as entry_source, transfers.id as movement_id, transfers.transfer_date as movement_date");

        $filters->view === 'removed'
            ? $transactions->whereNotNull('transactions.removed_at')
            : $transactions->whereNull('transactions.removed_at');

        if ($filters->from !== null) {
            $transactions->whereDate('transactions.transaction_date', '>=', $filters->from);
            $transfers->whereDate('transfers.transfer_date', '>=', $filters->from);
        }
        if ($filters->to !== null) {
            $transactions->whereDate('transactions.transaction_date', '<=', $filters->to);
            $transfers->whereDate('transfers.transfer_date', '<=', $filters->to);
        }
        if ($filters->status !== null) {
            $transactions->where('transactions.status', $filters->status);
            $transfers->where('transfers.status', $filters->status);
        }
        if ($filters->search !== null && $filters->search !== '') {
            $transactions->where('transactions.search_text', 'like', '%'.TransactionTextNormalizer::normalize($filters->search).'%');
            $transfers->where('transfers.search_text', 'like', '%'.TransferTextNormalizer::normalize($filters->search).'%');
        }
        if ($filters->financialAccountId !== null) {
            $transactions->where('transactions.financial_account_id', $filters->financialAccountId);
            $transfers->where(function ($query) use ($filters): void {
                $query->where('transfers.source_financial_account_id', $filters->financialAccountId)
                    ->orWhere('transfers.destination_financial_account_id', $filters->financialAccountId);
            });
        }
        if ($filters->categoryId !== null) {
            $transactions->where('transactions.category_id', $filters->categoryId);
        }
        if ($filters->transactionType() !== null) {
            $transactions->where('transactions.type', $filters->transactionType());
        }

        $unions = [];
        if ($filters->includesTransactions()) {
            $unions[] = $transactions;
        }
        if ($filters->includesTransfers()) {
            $unions[] = $transfers;
        }
        if ($unions === []) {
            $unions[] = $transactions->whereRaw('1 = 0');
        }

        $union = array_shift($unions);
        foreach ($unions as $next) {
            $union->unionAll($next);
        }

        return DB::query()->fromSub($union, 'movement_keys');
    }

    /**
     * @param  Collection<int, object>  $keys
     * @return array<int, array<string, mixed>>
     */
    private function entriesFor(User $user, $keys): array
    {
        $transactionIds = $keys->where('entry_source', 'transaction')->pluck('movement_id')->all();
        $transferIds = $keys->where('entry_source', 'transfer')->pluck('movement_id')->all();

        $transactions = Transaction::query()
            ->with(['financialAccount', 'category'])
            ->where('user_id', $user->id)
            ->whereIn('id', $transactionIds)
            ->get()
            ->keyBy('id');
        $transfers = Transfer::query()
            ->with(['sourceAccount', 'destinationAccount'])
            ->where('user_id', $user->id)
            ->whereIn('id', $transferIds)
            ->get()
            ->keyBy('id');

        $entries = [];
        foreach ($keys as $key) {
            $movement = $key->entry_source === 'transfer'
                ? $transfers->get($key->movement_id)
                : $transactions->get($key->movement_id);
            if ($movement === null) {
                continue;
            }

            $entries[] = (new FinancialHistoryResource($movement))->resolve();
        }

        return $entries;
    }

    private function ownedAccount(User $user, int $accountId): FinancialAccount
    {
        $account = FinancialAccount::query()->where('user_id', $user->id)->find($accountId);
        if (! $account instanceof FinancialAccount) {
            throw new NotFoundHttpException('Account not found or not accessible to the signed-in user.');
        }

        return $account;
    }
}
