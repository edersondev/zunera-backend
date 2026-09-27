<?php

declare(strict_types=1);

namespace App\Services\FinancialDashboard;

use App\Models\User;
use App\Services\CreditCards\RecognizedCardExpenseProjection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Realized card spending uses the installment's closing date and net amount.
 * Rules and unconfirmed occurrences never enter these totals.
 */
final class RecurringCardExpenseProjection
{
    public function __construct(private readonly RecognizedCardExpenseProjection $recognition) {}

    public function total(User $user, string $from, string $to): int
    {
        return (int) $this->installments($user, $from, $to)
            ->selectRaw('COALESCE(SUM(recognized.recognized_amount_centavos), 0) as total_centavos')
            ->value('total_centavos');
    }

    /** @return Collection<int, object> */
    public function byCategory(User $user, string $from, string $to): Collection
    {
        return $this->installments($user, $from, $to)
            ->join('categories', 'categories.id', '=', 'recognized.category_id')
            ->groupBy('categories.id', 'categories.name', 'categories.classification', 'categories.status')
            ->selectRaw('categories.id as category_id, categories.name as category_name, categories.classification as category_classification, categories.status as category_status')
            ->selectRaw('SUM(recognized.recognized_amount_centavos) as total_centavos')
            ->get();
    }

    /** @return Collection<int, object> */
    public function byDate(User $user, string $from, string $to): Collection
    {
        return $this->installments($user, $from, $to)
            ->groupBy('recognized.closing_date')
            ->selectRaw('recognized.closing_date as movement_date')
            ->selectRaw('SUM(recognized.recognized_amount_centavos) as expenses_centavos')
            ->get();
    }

    private function installments(User $user, string $from, string $to): Builder
    {
        return DB::query()->fromSub(
            $this->recognition->installments((int) $user->id, $from, $to),
            'recognized',
        );
    }
}
