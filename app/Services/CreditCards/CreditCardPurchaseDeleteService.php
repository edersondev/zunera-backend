<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Exceptions\CreditCards\CreditCardStateException;
use App\Models\CreditCard;
use App\Models\CreditCardCreditEvent;
use App\Models\CreditCardPaymentSurplusApplication;
use App\Models\CreditCardPurchase;
use App\Models\CreditCardStatement;
use App\Models\RecurringCardOccurrence;
use App\Models\User;
use App\Services\Notifications\BudgetNotificationProjector;
use App\Services\Notifications\RecurringReviewProjector;
use App\Services\Notifications\StatementNotificationProjector;
use App\Services\RecurringTransactions\RecurringIdempotencyService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreditCardPurchaseDeleteService
{
    public function __construct(
        private readonly CreditCardMutationIdempotencyService $idempotency,
        private readonly RecurringIdempotencyService $recurringIdempotency,
        private readonly CreditCardObligationReconciler $reconciler,
        private readonly BillingCycleCalculator $cycles,
        private readonly StatementNotificationProjector $notifications,
    ) {}

    /** @return array<string, mixed> */
    public function delete(User $user, int $purchaseId, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $purchaseId, $idempotencyKey): array {
            $fingerprint = $this->idempotency->fingerprint('purchase.delete:'.$purchaseId, []);

            return $this->idempotency->run((int) $user->id, $idempotencyKey, 'purchase.delete', $fingerprint, function () use ($user, $purchaseId): array {
                $candidate = CreditCardPurchase::query()->where('user_id', $user->id)->find($purchaseId);
                if (! $candidate instanceof CreditCardPurchase) {
                    throw new NotFoundHttpException('Purchase not found or not accessible to the signed-in user.');
                }
                $card = CreditCard::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($candidate->credit_card_id);
                $purchase = CreditCardPurchase::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($purchaseId);
                if (CreditCardCreditEvent::query()->where('credit_card_purchase_id', $purchaseId)->exists()) {
                    throw CreditCardStateException::purchaseHasLinkedCredits();
                }

                $installments = $purchase->installments()->with('statement')->orderBy('sequence')->lockForUpdate()->get();
                $statementIds = $installments->pluck('credit_card_statement_id')->unique()->all();
                $closingDates = $installments->map(fn ($installment): string => $installment->statement->closing_date->toDateString())->unique()->sort()->values()->all();
                $surplusBefore = CreditCardPaymentSurplusApplication::query()
                    ->where('credit_card_id', $card->id)->active()->pluck('destination_statement_id')->all();

                if ($purchase->recurring_card_occurrence_id !== null) {
                    $occurrence = RecurringCardOccurrence::query()->where('user_id', $user->id)
                        ->lockForUpdate()->findOrFail($purchase->recurring_card_occurrence_id);
                    $occurrence->purchase_deleted_at = now();
                    $occurrence->save();
                    app(RecurringReviewProjector::class)->captureCard($occurrence);
                }

                $this->idempotency->scrubPurchaseResponseSnapshots((int) $user->id, $purchaseId);
                $this->recurringIdempotency->scrubPurchaseResponseSnapshots((int) $user->id, $purchaseId);
                $purchase->delete();
                $this->reconciler->refreshCardStatements($card, $this->cycles->businessToday());

                $surplusAfter = CreditCardPaymentSurplusApplication::query()
                    ->where('credit_card_id', $card->id)->active()->pluck('destination_statement_id')->all();
                foreach (array_unique(array_merge($statementIds, $surplusBefore, $surplusAfter)) as $statementId) {
                    $this->notifications->capture(CreditCardStatement::query()->findOrFail($statementId));
                }
                foreach ($closingDates as $date) {
                    app(BudgetNotificationProjector::class)->captureDate((int) $user->id, $date);
                }

                return [
                    'target_type' => 'deleted_credit_card_purchase',
                    'target_id' => $purchaseId,
                    'status' => 200,
                    'response' => ['data' => [
                        'deleted_purchase_id' => $purchaseId,
                        'affected_statement_closing_dates' => $closingDates,
                    ]],
                ];
            });
        });
    }
}
