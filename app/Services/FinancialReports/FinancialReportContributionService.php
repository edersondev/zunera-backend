<?php

declare(strict_types=1);

namespace App\Services\FinancialReports;

use App\Data\FinancialReports\ReportScope;
use App\Repositories\FinancialReports\AccountMovementRepository;
use App\Repositories\FinancialReports\RecognizedContributionRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Bounded source pages and an independent all-record metric total. */
final class FinancialReportContributionService
{
    public function __construct(
        private readonly RecognizedContributionRepository $contributions,
        private readonly AccountMovementRepository $movements,
        private readonly ReportSourceRevisionService $revisions,
    ) {}

    /** @return array<string, mixed> */
    public function page(
        ReportScope $scope,
        string $metric,
        ?int $metricId = null,
        string $whichPeriod = 'current',
        ?string $cursor = null,
        int $limit = 50,
    ): array {
        return DB::transaction(fn (): array => $this->readPage($scope, $metric, $metricId, $whichPeriod, $cursor, $limit));
    }

    /** @return array<string, mixed> */
    private function readPage(
        ReportScope $scope,
        string $metric,
        ?int $metricId,
        string $whichPeriod,
        ?string $cursor,
        int $limit,
    ): array {
        $limit = max(1, min(100, $limit));
        $source = $this->metricRows($scope, $metric, $metricId, $whichPeriod);
        $total = (int) DB::query()->fromSub($source, 'all_rows')->sum('metric_amount_centavos');
        $page = DB::query()->fromSub($source, 'metric_row');

        if ($cursor !== null) {
            $after = $this->decodeCursor($cursor, $scope, $metric, $metricId, $whichPeriod);
            $page->where(function (Builder $query) use ($after): void {
                $query->where('metric_row.recognized_date', '>', $after['date'])
                    ->orWhere(function (Builder $sameDate) use ($after): void {
                        $sameDate->where('metric_row.recognized_date', $after['date'])
                            ->where(function (Builder $tie) use ($after): void {
                                $tie->where('metric_row.source_kind', '>', $after['kind'])
                                    ->orWhere(function (Builder $sameKind) use ($after): void {
                                        $sameKind->where('metric_row.source_kind', $after['kind'])
                                            ->where(function (Builder $sameSource) use ($after): void {
                                                $sameSource->where('metric_row.source_id', '>', $after['id'])
                                                    ->orWhere(function (Builder $sameId) use ($after): void {
                                                        $sameId->where('metric_row.source_id', $after['id'])
                                                            ->whereRaw('COALESCE(metric_row.related_installment_id, 0) > ?', [$after['installment']]);
                                                    });
                                            });
                                    });
                            });
                    });
            });
        }

        $rows = $page
            ->orderBy('metric_row.recognized_date')
            ->orderBy('metric_row.source_kind')
            ->orderBy('metric_row.source_id')
            ->orderByRaw('COALESCE(metric_row.related_installment_id, 0)')
            ->limit($limit + 1)
            ->get();
        $hasMore = $rows->count() > $limit;
        $visible = $rows->take($limit)->values();
        $last = $visible->last();

        return [
            'scope' => $scope->toArray(),
            'source_revision' => $this->revisions->forScope($scope),
            'which_period' => $whichPeriod,
            'metric' => $metric,
            'metric_id' => $metricId,
            'total' => ['amount_centavos' => $total, 'currency_code' => 'BRL'],
            'contributions' => $visible->all(),
            'next_cursor' => $hasMore && $last !== null
                ? $this->encodeCursor($scope, $metric, $metricId, $whichPeriod, $last)
                : null,
        ];
    }

    private function metricRows(ReportScope $scope, string $metric, ?int $metricId, string $whichPeriod): Builder
    {
        if (str_starts_with($metric, 'account_transfer_') || $metric === 'account_card_settlement') {
            $kind = match ($metric) {
                'account_transfer_in' => 'transfer_in',
                'account_transfer_out' => 'transfer_out',
                default => 'card_settlement',
            };

            return DB::query()->fromSub($this->movements->query($scope, $whichPeriod), 'source')
                ->where('source.account_id', $metricId)
                ->where('source.classification', $kind)
                ->selectRaw('source.source_kind, source.source_id, source.recognized_date, source.classification, NULL as category_id, source.account_id as financial_account_id, source.signed_amount_centavos, source.description, NULL as related_purchase_id, source.related_statement_id, NULL as related_installment_id, NULL as related_credit_event_id')
                ->selectRaw('NULL as category_name, NULL as category_classification, NULL as category_status, source.account_name, source.account_type, source.account_status')
                ->selectRaw('source.signed_amount_centavos as metric_amount_centavos');
        }

        $query = DB::query()->fromSub($this->contributions->query($scope, $whichPeriod), 'source');
        if ($metric === 'realized_income' || $metric === 'income_category' || $metric === 'account_income') {
            $query->where('source.classification', 'income');
        } elseif ($metric === 'realized_expenses' || $metric === 'expense_category' || $metric === 'account_expenses') {
            $query->where('source.classification', 'expense');
        }

        if ($metric === 'income_category' || $metric === 'expense_category') {
            $query->where('source.category_id', $metricId);
        }
        if (str_starts_with($metric, 'account_')) {
            $query->where('source.source_kind', 'ordinary_transaction')
                ->where('source.financial_account_id', $metricId);
        }

        $signed = in_array($metric, ['financial_result', 'account_net_flow'], true)
            ? "CASE WHEN source.classification = 'expense' THEN -source.signed_amount_centavos ELSE source.signed_amount_centavos END"
            : 'source.signed_amount_centavos';

        return $query->select('source.*')->selectRaw("{$signed} as metric_amount_centavos");
    }

    private function scopeHash(ReportScope $scope, string $metric, ?int $metricId, string $whichPeriod): string
    {
        return hash('sha256', json_encode([$scope->userId, $scope->toArray(), $metric, $metricId, $whichPeriod], JSON_THROW_ON_ERROR));
    }

    private function encodeCursor(ReportScope $scope, string $metric, ?int $metricId, string $whichPeriod, object $row): string
    {
        $position = [
            'scope' => $this->scopeHash($scope, $metric, $metricId, $whichPeriod),
            'date' => substr((string) $row->recognized_date, 0, 10),
            'kind' => (string) $row->source_kind,
            'id' => (int) $row->source_id,
            'installment' => (int) ($row->related_installment_id ?? 0),
        ];
        $json = json_encode($position, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $json, (string) config('app.key'));

        return rtrim(strtr(base64_encode($json.'.'.$signature), '+/', '-_'), '=');
    }

    /** @return array{date: string, kind: string, id: int, installment: int} */
    private function decodeCursor(string $token, ReportScope $scope, string $metric, ?int $metricId, string $whichPeriod): array
    {
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false || ! str_contains($raw, '.')) {
            throw ValidationException::withMessages(['cursor' => ['Invalid report cursor.']]);
        }
        [$json, $signature] = explode('.', $raw, 2);
        if (! hash_equals(hash_hmac('sha256', $json, (string) config('app.key')), $signature)) {
            throw ValidationException::withMessages(['cursor' => ['Invalid report cursor.']]);
        }
        $data = json_decode($json, true);
        if (! is_array($data)
            || ($data['scope'] ?? null) !== $this->scopeHash($scope, $metric, $metricId, $whichPeriod)
            || ! is_string($data['date'] ?? null)
            || ! is_string($data['kind'] ?? null)
            || ! is_int($data['id'] ?? null)
            || ! is_int($data['installment'] ?? null)) {
            throw ValidationException::withMessages(['cursor' => ['Invalid report cursor.']]);
        }

        return $data;
    }
}
