<?php

declare(strict_types=1);

namespace App\Services\FinancialReports;

use App\Data\FinancialReports\ReportScope;
use App\Models\FinancialAccount;
use App\Repositories\FinancialReports\AccountMovementRepository;
use App\Repositories\FinancialReports\RecognizedContributionRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Aggregates authoritative signed rows without storing report-only balances. */
final class FinancialReportService
{
    public function __construct(
        private readonly RecognizedContributionRepository $contributions,
        private readonly AccountMovementRepository $movements,
        private readonly ReportPeriodResolver $periods,
        private readonly ReportSourceRevisionService $revisions,
    ) {}

    /** @return array<string, mixed> */
    public function overview(ReportScope $scope): array
    {
        return DB::transaction(fn (): array => $this->readOverview($scope));
    }

    /** @return array<string, mixed> */
    private function readOverview(ReportScope $scope): array
    {
        $states = [];
        $current = $this->section($states, 'summary', fn (): array => $this->totals($scope));
        $evolution = $this->section($states, 'evolution', fn (): array => $this->evolution($scope));
        $expense = $this->section($states, 'expense_categories', fn (): array => $this->categories($scope, 'expense', $current['expense'] ?? 0));
        $income = $this->section($states, 'income_categories', fn (): array => $this->categories($scope, 'income', $current['income'] ?? 0));
        $accounts = $this->section($states, 'accounts', fn (): array => $this->accounts($scope));
        $comparison = $this->section($states, 'comparison', fn (): array => $this->comparison($scope, $current));

        if ($current === null && $evolution === null && $expense === null
            && $income === null && $accounts === null && $comparison === null) {
            throw new HttpException(503, 'Financial report is temporarily unavailable. Retry the report.');
        }

        $unattributed = null;
        if ($accounts !== null) {
            try {
                $unattributed = $this->money($this->unattributedCardExpenses($scope));
            } catch (Throwable) {
                $states['accounts'] = ['status' => 'unavailable', 'message' => 'Account activity is temporarily unavailable. Retry the report.'];
                $accounts = null;
            }
        }

        $empty = null;
        if ($current !== null) {
            try {
                $empty = $this->emptyStates($scope, $current);
            } catch (Throwable $error) {
                report($error);
            }
        }

        return [
            'scope' => $scope->toArray(),
            'source_revision' => $this->revisions->forScope($scope),
            'section_states' => $states,
            'summary' => $current === null ? null : [
                'realized_income' => $this->money($current['income']),
                'realized_expenses' => $this->money($current['expense']),
                'financial_result' => $this->money($current['income'] - $current['expense']),
            ],
            'evolution_granularity' => $evolution['granularity'] ?? null,
            'evolution' => $evolution['intervals'] ?? null,
            'expense_categories' => $expense,
            'income_categories' => $income,
            'accounts' => $accounts,
            'unattributed_card_expenses' => $unattributed,
            'comparison' => $comparison,
            'empty_states' => $empty,
        ];
    }

    /** @return array{income: int, expense: int, count: int} */
    private function totals(ReportScope $scope, string $which = 'current'): array
    {
        $row = $this->rows($scope, $which)
            ->selectRaw("COALESCE(SUM(CASE WHEN classification = 'income' THEN signed_amount_centavos ELSE 0 END), 0) as income")
            ->selectRaw("COALESCE(SUM(CASE WHEN classification = 'expense' THEN signed_amount_centavos ELSE 0 END), 0) as expense")
            ->selectRaw('COUNT(*) as contribution_count')
            ->first();

        return ['income' => (int) $row->income, 'expense' => (int) $row->expense, 'count' => (int) $row->contribution_count];
    }

    /** @return array{granularity: string, intervals: list<array<string, mixed>>} */
    private function evolution(ReportScope $scope): array
    {
        $period = $scope->period('current');
        $days = $period['day_count'];
        $granularity = $days <= 31 ? 'day' : ($days <= 93 ? 'week' : 'month');
        $daily = $this->rows($scope)
            ->groupBy('recognized_date')
            ->selectRaw('recognized_date')
            ->selectRaw("COALESCE(SUM(CASE WHEN classification = 'income' THEN signed_amount_centavos ELSE 0 END), 0) as income")
            ->selectRaw("COALESCE(SUM(CASE WHEN classification = 'expense' THEN signed_amount_centavos ELSE 0 END), 0) as expense")
            ->get()
            ->keyBy(static fn (object $row): string => substr((string) $row->recognized_date, 0, 10));

        $intervals = [];
        $start = CarbonImmutable::parse($period['from']);
        $end = CarbonImmutable::parse($period['to']);
        $cursor = $start;
        while ($cursor <= $end) {
            $naturalStart = match ($granularity) {
                'week' => $cursor->startOfWeek(CarbonImmutable::MONDAY),
                'month' => $cursor->startOfMonth(),
                default => $cursor,
            };
            $naturalEnd = match ($granularity) {
                'week' => $cursor->endOfWeek(CarbonImmutable::SUNDAY),
                'month' => $cursor->endOfMonth(),
                default => $cursor,
            };
            $bucketEnd = $naturalEnd->lessThan($end) ? $naturalEnd : $end;
            $income = 0;
            $expense = 0;
            for ($date = $cursor; $date <= $bucketEnd; $date = $date->addDay()) {
                $row = $daily->get($date->toDateString());
                $income += (int) ($row->income ?? 0);
                $expense += (int) ($row->expense ?? 0);
            }
            $intervals[] = [
                'from' => $cursor->toDateString(),
                'to' => $bucketEnd->toDateString(),
                'is_partial' => $cursor > $naturalStart || $bucketEnd < $naturalEnd,
                'realized_income' => $this->money($income),
                'realized_expenses' => $this->money($expense),
                'financial_result' => $this->money($income - $expense),
            ];
            $cursor = $bucketEnd->addDay();
        }

        return ['granularity' => $granularity, 'intervals' => $intervals];
    }

    /** @return list<array<string, mixed>> */
    private function categories(ReportScope $scope, string $classification, int $denominator, string $which = 'current'): array
    {
        $rows = $this->rows($scope, $which)
            ->where('classification', $classification)
            ->groupBy('category_id', 'category_name', 'category_classification', 'category_status')
            ->selectRaw('category_id, category_name, category_classification, category_status, SUM(signed_amount_centavos) as total_centavos')
            ->havingRaw('SUM(signed_amount_centavos) <> 0')
            ->orderByDesc('total_centavos')
            ->orderBy('category_id')
            ->get();

        return $rows->map(fn (object $row): array => [
            'category' => [
                'id' => (int) $row->category_id,
                'name' => (string) $row->category_name,
                'classification' => (string) $row->category_classification,
                'status' => (string) $row->category_status,
            ],
            'total' => $this->money((int) $row->total_centavos),
            'share_percent' => $denominator > 0 ? round(((int) $row->total_centavos / $denominator) * 100, 2) : null,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function accounts(ReportScope $scope): array
    {
        $direct = $this->rows($scope)
            ->where('source_kind', 'ordinary_transaction')
            ->groupBy('financial_account_id', 'classification')
            ->selectRaw('financial_account_id as account_id, classification, SUM(signed_amount_centavos) as amount_centavos')
            ->get();
        $movement = DB::query()->fromSub($this->movements->query($scope), 'movement')
            ->groupBy('account_id', 'classification')
            ->selectRaw('account_id, classification, SUM(signed_amount_centavos) as amount_centavos')
            ->get();

        $totals = [];
        foreach ($direct->concat($movement) as $row) {
            $totals[(int) $row->account_id][(string) $row->classification] = (int) $row->amount_centavos;
        }

        return FinancialAccount::query()
            ->where('user_id', $scope->userId)
            ->when($scope->accountId !== null, fn ($query) => $query->whereKey($scope->accountId))
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (FinancialAccount $account): bool => $scope->accountId !== null || isset($totals[$account->id]))
            ->map(function (FinancialAccount $account) use ($totals): array {
                $amounts = $totals[$account->id] ?? [];
                $income = $amounts['income'] ?? 0;
                $expense = $amounts['expense'] ?? 0;

                return [
                    'account' => [
                        'id' => (int) $account->id,
                        'name' => $account->name,
                        'type' => $account->account_type->value,
                        'status' => $account->status->value,
                    ],
                    'realized_income' => $this->money($income),
                    'direct_expenses' => $this->money($expense),
                    'net_financial_flow' => $this->money($income - $expense),
                    'transfer_in' => $this->money($amounts['transfer_in'] ?? 0),
                    'transfer_out' => $this->money($amounts['transfer_out'] ?? 0),
                    'card_settlement' => $this->money($amounts['card_settlement'] ?? 0),
                ];
            })->values()->all();
    }

    private function unattributedCardExpenses(ReportScope $scope): int
    {
        if ($scope->accountId !== null) {
            return 0;
        }

        return (int) $this->rows($scope)
            ->whereIn('source_kind', ['card_installment', 'card_credit_adjustment'])
            ->sum('signed_amount_centavos');
    }

    /** @param array{income: int, expense: int, count: int}|null $current */
    private function comparison(ReportScope $scope, ?array $current): array
    {
        $current ??= $this->totals($scope);
        $previous = $this->totals($scope, 'previous');
        $currentCategories = $this->categories($scope, 'expense', $current['expense']);
        $priorCategories = $this->categories($scope, 'expense', $previous['expense'], 'previous');
        $categories = [];
        foreach ([$currentCategories, $priorCategories] as $set) {
            foreach ($set as $entry) {
                $id = $entry['category']['id'];
                $categories[$id]['category'] = $entry['category'];
            }
        }
        $currentById = collect($currentCategories)->keyBy('category.id');
        $previousById = collect($priorCategories)->keyBy('category.id');
        foreach ($categories as $id => &$entry) {
            $now = $currentById->get($id)['total']['amount_centavos'] ?? 0;
            $prior = $previousById->get($id)['total']['amount_centavos'] ?? 0;
            $entry['amounts'] = $this->compareMoney($scope, $now, $prior);
        }
        unset($entry);

        return [
            'realized_income' => $this->compareMoney($scope, $current['income'], $previous['income']),
            'realized_expenses' => $this->compareMoney($scope, $current['expense'], $previous['expense']),
            'financial_result' => $this->compareMoney($scope, $current['income'] - $current['expense'], $previous['income'] - $previous['expense']),
            'expense_categories' => array_values($categories),
        ];
    }

    /** @return array<string, mixed> */
    private function compareMoney(ReportScope $scope, int $current, int $previous): array
    {
        $reason = $this->periods->percentUnavailableReason($scope, $current, $previous);

        return [
            'current' => $this->money($current),
            'previous' => $this->money($previous),
            'difference' => $this->money($current - $previous),
            'percent_change' => $reason === null ? round((($current - $previous) / $previous) * 100, 2) : null,
            'percent_unavailable_reason' => $reason,
        ];
    }

    /** @param array{income: int, expense: int, count: int} $current */
    private function emptyStates(ReportScope $scope, array $current): array
    {
        $previous = $this->totals($scope, 'previous');

        return [
            'no_activity' => $current['count'] === 0,
            'no_income' => $current['income'] === 0,
            'no_expenses' => $current['expense'] === 0,
            'no_filter_matches' => $scope->isFiltered() && $current['count'] === 0,
            'no_previous_activity' => $previous['count'] === 0,
        ];
    }

    private function rows(ReportScope $scope, string $which = 'current'): Builder
    {
        return DB::query()->fromSub($this->contributions->query($scope, $which), 'contribution');
    }

    /** @return array{amount_centavos: int, currency_code: string} */
    private function money(int $amount): array
    {
        return ['amount_centavos' => $amount, 'currency_code' => 'BRL'];
    }

    /** @template T @param array<string, array{status: string, message: ?string}> $states @param callable(): T $read @return T|null */
    private function section(array &$states, string $name, callable $read): mixed
    {
        try {
            $value = $read();
            $states[$name] = ['status' => 'available', 'message' => null];

            return $value;
        } catch (Throwable $error) {
            report($error);
            $states[$name] = ['status' => 'unavailable', 'message' => 'This section is temporarily unavailable. Retry the report.'];

            return null;
        }
    }
}
