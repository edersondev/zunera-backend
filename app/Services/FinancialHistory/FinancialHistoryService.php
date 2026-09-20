<?php

declare(strict_types=1);

namespace App\Services\FinancialHistory;

use App\Data\FinancialHistory\FinancialHistoryFilterData;
use App\Http\Resources\FinancialHistory\FinancialHistoryResource;
use App\Models\Category;
use App\Models\CreditCardInstallment;
use App\Models\FinancialAccount;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\CreditCards\CreditCardMoney;
use App\Services\RecurringTransactions\RecurringTransactionService;
use App\Services\Transactions\TransactionTextNormalizer;
use App\Services\Transfers\TransferTextNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FinancialHistoryService
{
    public function __construct(
        private readonly RecurringTransactionService $recurringTransactions,
    ) {}

    /**
     * Mixed income, expense, and transfer projection ordered newest first. One
     * paginated union query keeps same-date ordering and totals exact for 5,000+
     * movements without loading either side twice. The optional recurrence
     * projection is merged only when explicitly requested.
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
        if ($filters->categoryId !== null) {
            $this->availableCategory($user, $filters->categoryId);
        }

        $movements = $this->movementKeys($user, $filters);
        if ($filters->includesRecurring()) {
            return $this->listIncludingRecurring($user, $filters, $movements);
        }

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
     * Recurrence dates are derived from calendar rules, so the optional mixed
     * projection merges a bounded prefix from each independently ordered source.
     * The first page * per-page entries of each source are sufficient to form
     * the requested global page without loading all ordinary movements.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function listIncludingRecurring(User $user, FinancialHistoryFilterData $filters, Builder $movements): LengthAwarePaginator
    {
        $page = max(1, $filters->page);
        $prefixLimit = $page * $filters->perPage;
        $movementTotal = $movements->count();
        $movementKeys = $movements
            ->orderByDesc('movement_date')
            ->orderByDesc('movement_id')
            ->limit($prefixLimit)
            ->get();
        $recurringEntries = $this->recurringEntries($user, $filters);

        $entries = $this->orderEntries(
            collect($this->entriesFor($user, $movementKeys))
                ->concat($recurringEntries->take($prefixLimit)),
        )->slice(($page - 1) * $filters->perPage, $filters->perPage)->values()->all();

        return new LengthAwarePaginator(
            $entries,
            $movementTotal + $recurringEntries->count(),
            $filters->perPage,
            $page,
        );
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
            ->selectRaw("'transfer' as entry_source, transfers.id as movement_id, transfers.transfer_date as movement_date");

        // Recognized card installments enter history on their statement closing
        // date with the net recognized amount; settlement payments never do.
        $cardExpenses = DB::table('credit_card_installments')
            ->join('credit_card_statements', 'credit_card_statements.id', '=', 'credit_card_installments.credit_card_statement_id')
            ->join('credit_card_purchases', 'credit_card_purchases.id', '=', 'credit_card_installments.credit_card_purchase_id')
            ->where('credit_card_installments.user_id', $user->id)
            ->selectRaw("'credit_card_expense' as entry_source, credit_card_installments.id as movement_id, credit_card_statements.closing_date as movement_date");

        if ($filters->view === 'removed') {
            // Card installments have no removed lifecycle state of their own.
            $cardExpenses->whereRaw('1 = 0');
        } else {
            // Recognition is date-derived: an installment is effective from the
            // first business day after its statement closing date.
            $cardExpenses->whereDate(
                'credit_card_statements.closing_date',
                '<',
                CarbonImmutable::now(CreditCardMoney::BUSINESS_TIME_ZONE)->toDateString(),
            );
        }

        if ($filters->from !== null) {
            $cardExpenses->whereDate('credit_card_statements.closing_date', '>=', $filters->from);
        }
        if ($filters->to !== null) {
            $cardExpenses->whereDate('credit_card_statements.closing_date', '<=', $filters->to);
        }
        if ($filters->status !== null && $filters->status->value !== 'effective') {
            $cardExpenses->whereRaw('1 = 0');
        }
        if ($filters->search !== null && $filters->search !== '') {
            $cardExpenses->where('credit_card_purchases.description', 'like', '%'.TransactionTextNormalizer::normalize($filters->search).'%');
        }
        if ($filters->financialAccountId !== null) {
            // Card expenses never belong to a paying account.
            $cardExpenses->whereRaw('1 = 0');
        }
        if ($filters->categoryId !== null) {
            $cardExpenses->where('credit_card_purchases.category_id', $filters->categoryId);
        }
        if ($filters->transactionType() !== null) {
            $cardExpenses->whereRaw('1 = 0');
        }

        if ($filters->view === 'removed') {
            $transactions->whereNotNull('transactions.removed_at');
            $transfers->whereNotNull('transfers.removed_at');
        } else {
            $transactions->whereNull('transactions.removed_at');
            $transfers->whereNull('transfers.removed_at');
        }

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
        if ($filters->includesCreditCardExpenses()) {
            $unions[] = $cardExpenses;
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
        $cardExpenseIds = $keys->where('entry_source', 'credit_card_expense')->pluck('movement_id')->all();

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
        $cardExpenses = CreditCardInstallment::query()
            ->with(['purchase.category', 'purchase.creditCard', 'statement'])
            ->where('user_id', $user->id)
            ->whereIn('id', $cardExpenseIds)
            ->get()
            ->keyBy('id');

        $entries = [];
        foreach ($keys as $key) {
            $movement = match ($key->entry_source) {
                'transfer' => $transfers->get($key->movement_id),
                'credit_card_expense' => $cardExpenses->get($key->movement_id),
                default => $transactions->get($key->movement_id),
            };
            if ($movement === null) {
                continue;
            }

            $entries[] = (new FinancialHistoryResource($movement))->resolve();
        }

        return $entries;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function recurringEntries(User $user, FinancialHistoryFilterData $filters): Collection
    {
        $rules = RecurringTransaction::query()
            ->with(['financialAccount', 'category'])
            ->where('user_id', $user->id)
            ->when(
                $filters->transactionType() !== null,
                fn ($query) => $query->where('type', $filters->transactionType()),
            )
            ->when(
                $filters->financialAccountId !== null,
                fn ($query) => $query->where('financial_account_id', $filters->financialAccountId),
            )
            ->when(
                $filters->categoryId !== null,
                fn ($query) => $query->where('category_id', $filters->categoryId),
            )
            ->when(
                $filters->search !== null && $filters->search !== '',
                function ($query) use ($filters): void {
                    $query->where(function ($textQuery) use ($filters): void {
                        $textQuery
                            ->where('description', 'like', '%'.$filters->search.'%')
                            ->orWhere('notes', 'like', '%'.$filters->search.'%');
                    });
                },
            )
            ->orderBy('id')
            ->get();

        $this->recurringTransactions->decorateForProjection($rules);

        return $rules
            ->filter(function (RecurringTransaction $rule) use ($filters): bool {
                $date = $rule->getAttribute('next_expected_occurrence');
                if (! is_string($date) || $date === '') {
                    return $filters->from === null && $filters->to === null;
                }

                return ($filters->from === null || $date >= $filters->from)
                    && ($filters->to === null || $date <= $filters->to);
            })
            ->sort(function (RecurringTransaction $left, RecurringTransaction $right): int {
                return $this->compareHistoryPosition(
                    $left->getAttribute('next_expected_occurrence'),
                    $left->id,
                    $right->getAttribute('next_expected_occurrence'),
                    $right->id,
                );
            })
            ->map(fn (RecurringTransaction $rule): array => (new FinancialHistoryResource($rule))->resolve())
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function orderEntries(Collection $entries): Collection
    {
        return $entries->sort(function (array $left, array $right): int {
            return $this->compareHistoryPosition(
                $left['movement_date'] ?? null,
                (int) $left['id'],
                $right['movement_date'] ?? null,
                (int) $right['id'],
            );
        })->values();
    }

    private function compareHistoryPosition(mixed $leftDate, int $leftId, mixed $rightDate, int $rightId): int
    {
        $left = is_string($leftDate) && $leftDate !== '' ? $leftDate : null;
        $right = is_string($rightDate) && $rightDate !== '' ? $rightDate : null;

        if ($left === null || $right === null) {
            if ($left === $right) {
                return $rightId <=> $leftId;
            }

            return $left === null ? 1 : -1;
        }

        $byDate = strcmp($right, $left);

        return $byDate !== 0 ? $byDate : $rightId <=> $leftId;
    }

    private function ownedAccount(User $user, int $accountId): FinancialAccount
    {
        $account = FinancialAccount::query()->where('user_id', $user->id)->find($accountId);
        if (! $account instanceof FinancialAccount) {
            throw new NotFoundHttpException('Account not found or not accessible to the signed-in user.');
        }

        return $account;
    }

    private function availableCategory(User $user, int $categoryId): Category
    {
        $category = Category::query()
            ->where('id', $categoryId)
            ->where(function ($query) use ($user): void {
                $query->where('origin', 'system')->orWhere(function ($personal) use ($user): void {
                    $personal->where('origin', 'personal')->where('user_id', $user->id);
                });
            })
            ->first();
        if (! $category instanceof Category) {
            throw new NotFoundHttpException('Category not found or not accessible to the signed-in user.');
        }

        return $category;
    }
}
