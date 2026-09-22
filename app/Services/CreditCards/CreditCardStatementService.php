<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Data\CreditCards\StatementResponseData;
use App\Enums\CreditCards\CreditCardStatementStatus;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreditCardStatementService
{
    public function __construct(
        private readonly CreditCardObligationReconciler $reconciler,
        private readonly BillingCycleCalculator $cycles,
    ) {}

    /** @return LengthAwarePaginator<int, CreditCardStatement> */
    public function list(User $user, CreditCard $card, ?CreditCardStatementStatus $status, int $page, int $perPage): LengthAwarePaginator
    {
        DB::transaction(fn () => $this->reconciler->refreshCardStatements($card, $this->businessDate()));

        return CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->where('user_id', $user->id)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('closing_date')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function findOwned(User $user, int $statementId): CreditCardStatement
    {
        $statement = CreditCardStatement::query()
            ->with(['creditCard', 'installments.purchase.category', 'payments.financialAccount', 'creditApplications.creditEvent'])
            ->where('user_id', $user->id)
            ->find($statementId);

        if (! $statement instanceof CreditCardStatement) {
            throw new NotFoundHttpException('Statement not found or not accessible to the signed-in user.');
        }

        DB::transaction(fn () => $this->reconciler->refreshCardStatements($statement->creditCard, $this->businessDate()));

        return $statement->refresh();
    }

    public function refresh(User $user, CreditCard $card): void
    {
        if ((int) $card->user_id !== (int) $user->id) {
            throw new NotFoundHttpException('Credit card not found or not accessible to the signed-in user.');
        }

        DB::transaction(fn () => $this->reconciler->refreshCardStatements($card, $this->businessDate()));
    }

    /** @return array<int, array<string, mixed>> */
    public function summariesOf(CreditCard $card, array $statementIds, bool $includeSynthesizedCurrent = false): array
    {
        $businessDate = $this->businessDate();
        $statements = CreditCardStatement::query()
            ->whereIn('id', $statementIds)
            ->orderBy('closing_date')
            ->get();

        $summaries = $statements
            ->map(fn (CreditCardStatement $statement) => StatementResponseData::summary(
                $statement,
                $card,
                StatementResponseData::isCurrentCycle($card, $statement, $businessDate),
                $businessDate,
            ))
            ->all();

        if ($statements->isEmpty() && $includeSynthesizedCurrent) {
            $summaries[] = StatementResponseData::synthesizedCurrent($card, $businessDate, $card->isActive());
        }

        return $summaries;
    }

    public function syncStatement(CreditCardStatement $statement): CreditCardStatement
    {
        $this->reconciler->syncStatement($statement, $this->businessDate());

        return $statement->refresh();
    }

    private function businessDate(): CarbonImmutable
    {
        return $this->cycles->businessToday();
    }
}
