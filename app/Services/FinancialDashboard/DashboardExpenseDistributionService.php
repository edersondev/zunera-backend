<?php

declare(strict_types=1);

namespace App\Services\FinancialDashboard;

use App\Data\FinancialDashboard\DashboardPeriodData;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Realized expense allocation by category for the reporting period. Archived
 * categories stay representable because historical expenses keep their meaning.
 */
final class DashboardExpenseDistributionService
{
    public function __construct(private readonly RecurringCardExpenseProjection $cardExpenses) {}

    /** @return array<string, mixed> */
    public function distribution(User $user, DashboardPeriodData $period): array
    {
        $rows = DB::table('transactions')
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.user_id', $user->id)
            ->whereNull('transactions.removed_at')
            ->where('transactions.status', 'effective')
            ->where('transactions.type', 'expense')
            ->whereDate('transactions.transaction_date', '>=', $period->from)
            ->whereDate('transactions.transaction_date', '<=', $period->to)
            ->groupBy('categories.id', 'categories.name', 'categories.classification', 'categories.status')
            ->selectRaw('categories.id as category_id')
            ->selectRaw('categories.name as category_name')
            ->selectRaw('categories.classification as category_classification')
            ->selectRaw('categories.status as category_status')
            ->selectRaw('SUM(transactions.amount_centavos) as total_centavos')
            ->orderByDesc('total_centavos')
            ->orderBy('categories.id')
            ->get();

        $totals = [];
        foreach ($rows->concat($this->cardExpenses->byCategory($user, $period->from, $period->to)) as $row) {
            $categoryId = (int) $row->category_id;
            $categoryTotal = (int) $row->total_centavos;
            if ($categoryTotal <= 0) {
                continue;
            }
            if (! isset($totals[$categoryId])) {
                $totals[$categoryId] = [
                    'category' => [
                        'id' => $categoryId,
                        'name' => (string) $row->category_name,
                        'classification' => (string) $row->category_classification,
                        'status' => (string) $row->category_status,
                    ],
                    'total_centavos' => 0,
                ];
            }
            $totals[$categoryId]['total_centavos'] += $categoryTotal;
        }

        $categories = array_values($totals);
        usort($categories, static fn (array $left, array $right): int => [$right['total_centavos'], $left['category']['id']] <=> [$left['total_centavos'], $right['category']['id']]);

        $total = array_sum(array_column($categories, 'total_centavos'));
        foreach ($categories as $index => &$category) {
            $categoryTotal = $category['total_centavos'];
            $category['share_percent'] = $total === 0 ? 0.0 : round($categoryTotal / $total * 100, 2);
            $category['rank'] = $index + 1;
        }
        unset($category);

        return [
            'period' => $period,
            'total_expenses_centavos' => $total,
            'currency_code' => 'BRL',
            'categories' => $categories,
        ];
    }
}
