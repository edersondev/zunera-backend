<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Data\CreditCards\BillingCycleData;
use App\Data\CreditCards\CreatePurchaseData;
use App\Data\CreditCards\PurchaseResponseData;
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
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreditCardPurchaseService
{
    public function __construct(
        private readonly CreditCardMutationIdempotencyService $idempotency,
        private readonly CreditCardObligationReconciler $reconciler,
        private readonly BillingCycleCalculator $cycles,
        private readonly InstallmentAllocator $allocator,
    ) {}

    /** @return array{target_type: string, target_id: int, status: int, response: array<string, mixed>, replayed: bool, purchase: CreditCardPurchase} */
    public function create(User $user, CreditCard $card, CreatePurchaseData $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $card, $data, $idempotencyKey): array {
            $lockedCard = $this->lockOwnedCard($user, $card->id);
            if (! $lockedCard->isActive()) {
                throw CreditCardStateException::cardArchived();
            }

            $fingerprint = $this->idempotency->fingerprint('purchase.create:'.$lockedCard->id, [
                'category_id' => $data->categoryId,
                'description' => $data->description,
                'notes' => $data->notes,
                'purchase_date' => $data->purchaseDate,
                'total_amount_centavos' => $data->totalAmountCentavos,
                'installment_count' => $data->installmentCount,
                'confirm_over_limit' => $data->overLimitConfirmed,
                'expected_available_credit_centavos' => $data->confirmedAvailableCreditCentavos,
            ]);

            $result = $this->idempotency->run($user->id, $idempotencyKey, 'purchase.create', $fingerprint, function () use ($user, $lockedCard, $data): array {
                $category = $this->availableExpenseCategory($user, $data->categoryId);
                if ($data->installmentCount > $data->totalAmountCentavos) {
                    throw ValidationException::withMessages([
                        'installment_count' => ['Each installment must be at least one centavo.'],
                    ]);
                }
                $this->assertWithinAvailableCredit($lockedCard, $data);

                $purchase = CreditCardPurchase::query()->create([
                    'user_id' => $user->id,
                    'credit_card_id' => $lockedCard->id,
                    'category_id' => $category->id,
                    'description' => $data->description,
                    'notes' => $data->notes,
                    'total_amount_centavos' => $data->totalAmountCentavos,
                    'installment_count' => $data->installmentCount,
                    'purchase_date' => $data->purchaseDate,
                    'currency_code' => 'BRL',
                    'card_name_snapshot' => $lockedCard->name,
                    'category_name_snapshot' => $category->name,
                    'category_status_snapshot' => $category->status->value,
                ]);

                $cycle = $this->cycles->cycleForDate($data->purchaseDate, $lockedCard->closing_day, $lockedCard->due_day);
                foreach ($this->allocator->allocate($data->totalAmountCentavos, $data->installmentCount) as $index => $amountCentavos) {
                    $statement = $this->statementFor($lockedCard, $cycle);
                    CreditCardInstallment::query()->create([
                        'user_id' => $user->id,
                        'credit_card_purchase_id' => $purchase->id,
                        'credit_card_id' => $lockedCard->id,
                        'credit_card_statement_id' => $statement->id,
                        'sequence' => $index + 1,
                        'amount_centavos' => $amountCentavos,
                        'credit_adjustment_centavos' => 0,
                        'recognition_date' => $cycle->closingDate,
                    ]);
                    $cycle = $this->cycles->nextCycle($cycle, $lockedCard->closing_day, $lockedCard->due_day);
                }

                $this->reconciler->refreshCardStatements($lockedCard, $this->businessDate());
                $fresh = $purchase->refresh();

                return [
                    'target_type' => 'credit_card_purchase',
                    'target_id' => $fresh->id,
                    'status' => 201,
                    'response' => ['data' => PurchaseResponseData::from($fresh, PurchaseResponseData::isDirectlyEditable($fresh))],
                ];
            });

            /** @var CreditCardPurchase $purchase */
            $purchase = CreditCardPurchase::query()->where('user_id', $user->id)->findOrFail($result['target_id']);

            return [...$result, 'purchase' => $purchase];
        });
    }

    public function findOwned(User $user, int $purchaseId): CreditCardPurchase
    {
        $purchase = CreditCardPurchase::query()
            ->with(['creditCard', 'category', 'installments.statement', 'creditEvents.applications'])
            ->where('user_id', $user->id)
            ->find($purchaseId);

        if (! $purchase instanceof CreditCardPurchase) {
            throw new NotFoundHttpException('Purchase not found or not accessible to the signed-in user.');
        }

        return $purchase;
    }

    /** @return LengthAwarePaginator<int, CreditCardPurchase> */
    public function list(User $user, CreditCard $card, int $page, int $perPage): LengthAwarePaginator
    {
        return CreditCardPurchase::query()
            ->with(['creditCard', 'category', 'installments.statement', 'creditEvents.applications'])
            ->where('user_id', $user->id)
            ->where('credit_card_id', $card->id)
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function findOwnedCard(User $user, int $cardId): CreditCard
    {
        return $this->lockOwnedCard($user, $cardId, false);
    }

    private function assertWithinAvailableCredit(CreditCard $card, CreatePurchaseData $data): void
    {
        $available = $this->reconciler->availableCreditCentavos($card);
        if ($data->totalAmountCentavos <= $available) {
            return;
        }

        if (! $data->overLimitConfirmed) {
            throw CreditCardStateException::overLimitConfirmationRequired($available - $data->totalAmountCentavos);
        }

        if ($data->confirmedAvailableCreditCentavos !== null && $data->confirmedAvailableCreditCentavos !== $available) {
            throw CreditCardStateException::staleOverLimitConfirmation();
        }
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

    public function statementFor(CreditCard $card, BillingCycleData $cycle): CreditCardStatement
    {
        $statement = CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->whereDate('closing_date', $cycle->closingDate)
            ->lockForUpdate()
            ->first();

        if ($statement instanceof CreditCardStatement) {
            return $statement;
        }

        return CreditCardStatement::query()->create([
            'user_id' => $card->user_id,
            'credit_card_id' => $card->id,
            'period_from' => $cycle->periodFrom,
            'period_to' => $cycle->periodTo,
            'closing_date' => $cycle->closingDate,
            'due_date' => $cycle->dueDate,
            'status' => 'open',
        ]);
    }

    private function lockOwnedCard(User $user, int $cardId, bool $lock = true): CreditCard
    {
        $query = CreditCard::query()->where('user_id', $user->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        $card = $query->find($cardId);

        if (! $card instanceof CreditCard) {
            throw new NotFoundHttpException('Credit card not found or not accessible to the signed-in user.');
        }

        return $card;
    }

    private function businessDate(): CarbonImmutable
    {
        return $this->cycles->businessToday();
    }
}
