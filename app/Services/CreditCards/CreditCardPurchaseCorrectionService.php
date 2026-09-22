<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Data\CreditCards\BillingCycleData;
use App\Data\CreditCards\PurchaseResponseData;
use App\Data\CreditCards\UpdatePurchaseData;
use App\Enums\Categories\CategoryClassification;
use App\Enums\Categories\CategoryStatus;
use App\Exceptions\CreditCards\CreditCardStateException;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardInstallment;
use App\Models\CreditCardPurchase;
use App\Models\CreditCardStatement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Direct correction of a purchase is only allowed while every one of its
 * installments still belongs to an open statement, so nothing communicated as a
 * closed bill is silently rewritten. Closed-statement changes go through
 * traceable credit events instead.
 */
final class CreditCardPurchaseCorrectionService
{
    public function __construct(
        private readonly CreditCardMutationIdempotencyService $idempotency,
        private readonly CreditCardObligationReconciler $reconciler,
        private readonly BillingCycleCalculator $cycles,
        private readonly InstallmentAllocator $allocator,
    ) {}

    /** @return array<string, mixed> */
    public function update(
        User $user,
        CreditCardPurchase $purchase,
        UpdatePurchaseData $data,
        bool $overLimitConfirmed,
        ?int $expectedAvailableCreditCentavos,
        string $idempotencyKey,
    ): array {
        return DB::transaction(function () use ($user, $purchase, $data, $overLimitConfirmed, $expectedAvailableCreditCentavos, $idempotencyKey): array {
            $locked = CreditCardPurchase::query()->where('user_id', $user->id)->lockForUpdate()->find($purchase->id);
            if (! $locked instanceof CreditCardPurchase) {
                throw new NotFoundHttpException('Purchase not found or not accessible to the signed-in user.');
            }

            $fingerprint = $this->idempotency->fingerprint('purchase.update:'.$locked->id, $data->changes + [
                'confirm_over_limit' => $overLimitConfirmed,
                'expected_available_credit_centavos' => $expectedAvailableCreditCentavos,
            ]);

            $result = $this->idempotency->run($user->id, $idempotencyKey, 'purchase.update', $fingerprint, function () use ($user, $locked, $data, $overLimitConfirmed, $expectedAvailableCreditCentavos): array {
                $this->assertDirectlyEditable($locked);

                $card = $data->has('card_id')
                    ? $this->activeOwnedCard($user, (int) $data->changes['card_id'])
                    : CreditCard::query()->lockForUpdate()->findOrFail($locked->credit_card_id);
                $category = $data->has('category_id')
                    ? $this->availableExpenseCategory($user, (int) $data->changes['category_id'])
                    : Category::query()->findOrFail($locked->category_id);

                $total = $data->has('total_amount_centavos') ? (int) $data->changes['total_amount_centavos'] : (int) $locked->total_amount_centavos;
                $count = $data->has('installment_count') ? (int) $data->changes['installment_count'] : (int) $locked->installment_count;
                $purchaseDate = $data->has('purchase_date') ? (string) $data->changes['purchase_date'] : $locked->purchase_date->toDateString();

                if ($count > $total) {
                    throw ValidationException::withMessages(['installment_count' => ['Each installment must be at least one centavo.']]);
                }

                $this->assertCorrectionFits($locked, $card, $total, $overLimitConfirmed, $expectedAvailableCreditCentavos);
                $this->assertTargetCyclesStayOpen($card, $purchaseDate, $count);

                $previousStatementIds = CreditCardInstallment::query()
                    ->where('credit_card_purchase_id', $locked->id)
                    ->pluck('credit_card_statement_id')
                    ->all();

                $locked->fill(array_filter([
                    'credit_card_id' => $data->has('card_id') ? $card->id : null,
                    'category_id' => $data->has('category_id') ? $category->id : null,
                    'description' => $data->has('description') ? (string) $data->changes['description'] : null,
                    'notes' => $data->has('notes') ? $data->changes['notes'] : null,
                    'purchase_date' => $data->has('purchase_date') ? $purchaseDate : null,
                    'total_amount_centavos' => $data->has('total_amount_centavos') ? $total : null,
                    'installment_count' => $data->has('installment_count') ? $count : null,
                    'card_name_snapshot' => $card->name,
                    'category_name_snapshot' => $category->name,
                    'category_status_snapshot' => $category->status->value,
                ], fn ($value) => $value !== null));
                $locked->save();

                CreditCardInstallment::query()->where('credit_card_purchase_id', $locked->id)->delete();

                $cycle = $this->cycles->cycleForDate($purchaseDate, $card->closing_day, $card->due_day);
                foreach ($this->allocator->allocate($total, $count) as $index => $amountCentavos) {
                    $statement = $this->statementFor($card, $cycle);
                    CreditCardInstallment::query()->create([
                        'user_id' => $user->id,
                        'credit_card_purchase_id' => $locked->id,
                        'credit_card_id' => $card->id,
                        'credit_card_statement_id' => $statement->id,
                        'sequence' => $index + 1,
                        'amount_centavos' => $amountCentavos,
                        'credit_adjustment_centavos' => 0,
                        'recognition_date' => $cycle->closingDate,
                    ]);
                    $cycle = $this->cycles->nextCycle($cycle, $card->closing_day, $card->due_day);
                }

                $this->reconciler->refreshCardStatements($card, $this->businessDate());
                $this->pruneEmptyStatements($previousStatementIds);
                $fresh = $locked->refresh();

                return [
                    'target_type' => 'credit_card_purchase',
                    'target_id' => $fresh->id,
                    'status' => 200,
                    'response' => ['data' => PurchaseResponseData::from($fresh, PurchaseResponseData::isDirectlyEditable($fresh))],
                ];
            });

            /** @var CreditCardPurchase $fresh */
            $fresh = CreditCardPurchase::query()->findOrFail($result['target_id']);

            return [...$result, 'purchase' => $fresh];
        });
    }

    public function assertDirectlyEditable(CreditCardPurchase $purchase): void
    {
        $businessDate = $this->businessDate();
        $installments = CreditCardInstallment::query()
            ->with('statement')
            ->where('credit_card_purchase_id', $purchase->id)
            ->get();

        foreach ($installments as $installment) {
            $statement = $installment->statement;
            if (! $statement instanceof CreditCardStatement || ! $this->cycles->isOpenOn($businessDate, $statement->closing_date)) {
                throw CreditCardStateException::purchaseNotDirectlyEditable();
            }
        }
    }

    /**
     * A correction may only restate statements that are still open, so the
     * corrected purchase date cannot push installments into a closed bill.
     */
    private function assertTargetCyclesStayOpen(CreditCard $card, string $purchaseDate, int $installmentCount): void
    {
        $businessDate = $this->businessDate();
        $cycle = $this->cycles->cycleForDate($purchaseDate, $card->closing_day, $card->due_day);

        for ($sequence = 1; $sequence <= $installmentCount; $sequence++) {
            if (! $this->cycles->isOpenOn($businessDate, $cycle->closingDate)) {
                throw CreditCardStateException::purchaseNotDirectlyEditable();
            }
            $cycle = $this->cycles->nextCycle($cycle, $card->closing_day, $card->due_day);
        }
    }

    private function assertCorrectionFits(
        CreditCardPurchase $purchase,
        CreditCard $card,
        int $newTotalCentavos,
        bool $overLimitConfirmed,
        ?int $expectedAvailableCreditCentavos,
    ): void {
        $currentPrincipal = (int) CreditCardInstallment::query()
            ->where('credit_card_purchase_id', $purchase->id)
            ->selectRaw('COALESCE(SUM(amount_centavos - credit_adjustment_centavos), 0) as total')
            ->value('total');

        $available = $this->reconciler->availableCreditCentavos($card) + $currentPrincipal;
        if ($newTotalCentavos <= $available) {
            return;
        }

        if (! $overLimitConfirmed) {
            throw CreditCardStateException::overLimitConfirmationRequired($available - $newTotalCentavos);
        }

        if ($expectedAvailableCreditCentavos !== null && $expectedAvailableCreditCentavos !== $available) {
            throw CreditCardStateException::staleOverLimitConfirmation();
        }
    }

    private function activeOwnedCard(User $user, int $cardId): CreditCard
    {
        $card = CreditCard::query()->where('user_id', $user->id)->lockForUpdate()->find($cardId);
        if (! $card instanceof CreditCard) {
            throw new NotFoundHttpException('Credit card not found or not accessible to the signed-in user.');
        }
        if (! $card->isActive()) {
            throw CreditCardStateException::cardArchived();
        }

        return $card;
    }

    private function availableExpenseCategory(User $user, int $categoryId): Category
    {
        $category = Category::query()->where('user_id', $user->id)->find($categoryId);
        if (! $category instanceof Category) {
            throw ValidationException::withMessages(['category_id' => ['Select an existing expense category you own.']]);
        }
        if ($category->classification !== CategoryClassification::Expense) {
            throw ValidationException::withMessages(['category_id' => ['Credit card purchases require an expense category.']]);
        }
        if ($category->status !== CategoryStatus::Active) {
            throw ValidationException::withMessages(['category_id' => ['Select an active category for new purchases.']]);
        }

        return $category;
    }

    private function statementFor(CreditCard $card, BillingCycleData $cycle): CreditCardStatement
    {
        $statement = CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->whereDate('closing_date', $cycle->closingDate)
            ->lockForUpdate()
            ->first();

        return $statement instanceof CreditCardStatement
            ? $statement
            : CreditCardStatement::query()->create([
                'user_id' => $card->user_id,
                'credit_card_id' => $card->id,
                'period_from' => $cycle->periodFrom,
                'period_to' => $cycle->periodTo,
                'closing_date' => $cycle->closingDate,
                'due_date' => $cycle->dueDate,
                'status' => 'open',
            ]);
    }

    /** @param list<int|null> $statementIds */
    private function pruneEmptyStatements(array $statementIds): void
    {
        foreach (array_filter($statementIds) as $statementId) {
            $hasInstallments = CreditCardInstallment::query()->where('credit_card_statement_id', $statementId)->exists();
            $hasPayments = DB::table('credit_card_statement_payments')->where('credit_card_statement_id', $statementId)->exists();
            $hasCredits = DB::table('credit_card_credit_applications')->where('credit_card_statement_id', $statementId)->exists();

            if (! $hasInstallments && ! $hasPayments && ! $hasCredits) {
                CreditCardStatement::query()->whereKey($statementId)->delete();
            }
        }
    }

    private function businessDate(): CarbonImmutable
    {
        return $this->cycles->businessToday();
    }
}
