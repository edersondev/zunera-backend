<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Data\CreditCards\CreateCreditEventData;
use App\Data\CreditCards\CreditCardResponseData;
use App\Data\CreditCards\CreditEventResponseData;
use App\Data\CreditCards\StatementResponseData;
use App\Enums\CreditCards\CreditCardCreditApplicationKind;
use App\Enums\CreditCards\CreditCardCreditEventReason;
use App\Exceptions\CreditCards\CreditCardStateException;
use App\Models\CreditCard;
use App\Models\CreditCardCreditApplication;
use App\Models\CreditCardCreditEvent;
use App\Models\CreditCardInstallment;
use App\Models\CreditCardPurchase;
use App\Models\CreditCardStatement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Traceable cancellations, refunds, and post-closing corrections. A credit
 * event never deletes purchase history: it reduces the unpaid part of its own
 * installments first, then automatically settles the oldest unpaid statements,
 * and only then leaves reusable card credit behind.
 */
final class CreditCardCreditEventService
{
    public function __construct(
        private readonly CreditCardMutationIdempotencyService $idempotency,
        private readonly CreditCardObligationReconciler $reconciler,
        private readonly BillingCycleCalculator $cycles,
    ) {}

    /** @return array{event: CreditCardCreditEvent, card: CreditCard, applications: array<int, int>, replayed: bool} */
    public function record(User $user, CreditCardPurchase $purchase, CreateCreditEventData $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $purchase, $data, $idempotencyKey): array {
            $locked = CreditCardPurchase::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->find($purchase->id);

            if (! $locked instanceof CreditCardPurchase) {
                throw new NotFoundHttpException('Purchase not found or not accessible to the signed-in user.');
            }

            $fingerprint = $this->idempotency->fingerprint('credit_event.record:'.$locked->id, [
                'reason' => $data->reason->value,
                'amount_centavos' => $data->amountCentavos,
                'event_date' => $data->eventDate,
                'notes' => $data->notes,
            ]);

            $result = $this->idempotency->run($user->id, $idempotencyKey, 'credit_event.record', $fingerprint, function () use ($user, $locked, $data): array {
                $card = CreditCard::query()->lockForUpdate()->findOrFail($locked->credit_card_id);
                $uncredited = $this->uncreditedCentavos($locked);

                if ($data->amountCentavos > $uncredited) {
                    throw CreditCardStateException::creditEventExceedsUncredited($uncredited);
                }

                $event = CreditCardCreditEvent::query()->create([
                    'user_id' => $user->id,
                    'credit_card_id' => $card->id,
                    'credit_card_purchase_id' => $locked->id,
                    'reason' => $data->reason,
                    'amount_centavos' => $data->amountCentavos,
                    'currency_code' => 'BRL',
                    'event_date' => $data->eventDate,
                    'notes' => $data->notes,
                ]);

                $affected = $this->applyEvent($event, $card, $data->eventDate);
                $this->reconciler->refreshCardStatements($card, $this->businessDate());

                return [
                    'target_type' => 'credit_event',
                    'target_id' => $event->id,
                    'status' => 201,
                    'response' => ['data' => [
                        'credit_event' => CreditEventResponseData::from($event->refresh()),
                        'card' => CreditCardResponseData::card($card->refresh(), $this->reconciler, $this->businessDate()),
                        'affected_statements' => array_map(
                            fn (int $statementId) => StatementResponseData::summary(
                                CreditCardStatement::query()->findOrFail($statementId),
                                $card,
                                false,
                                $this->businessDate(),
                            ),
                            array_map('intval', array_keys($affected)),
                        ),
                    ]],
                    'applications' => $affected,
                ];
            });

            /** @var CreditCardCreditEvent $event */
            $event = CreditCardCreditEvent::query()->findOrFail($result['target_id']);
            /** @var CreditCard $card */
            $card = CreditCard::query()->findOrFail($event->credit_card_id);

            return [
                'event' => $event,
                'card' => $card,
                'applications' => $result['applications'] ?? [],
                'response' => $result['response'] ?? [],
                'replayed' => $result['replayed'],
            ];
        });
    }

    /** Cancellation reverses every remaining centavo of the source purchase. */
    public function cancel(User $user, CreditCardPurchase $purchase, string $eventDate, ?string $notes, string $idempotencyKey): array
    {
        return $this->record($user, $purchase, new CreateCreditEventData(
            userId: (int) $user->id,
            reason: CreditCardCreditEventReason::Cancellation,
            amountCentavos: max(1, $this->uncreditedCentavos($purchase)),
            eventDate: $eventDate,
            notes: $notes,
        ), $idempotencyKey);
    }

    public function uncreditedCentavos(CreditCardPurchase $purchase): int
    {
        $total = (int) DB::table('credit_card_installments')
            ->where('credit_card_purchase_id', $purchase->id)
            ->sum('amount_centavos');

        $credited = (int) DB::table('credit_card_credit_events')
            ->where('credit_card_purchase_id', $purchase->id)
            ->sum('amount_centavos');

        return max(0, $total - $credited);
    }

    /**
     * @return array<int, int> statement id => applied amount
     */
    private function applyEvent(CreditCardCreditEvent $event, CreditCard $card, string $eventDate): array
    {
        $remaining = $event->amount_centavos;
        $affected = [];

        $installments = CreditCardInstallment::query()
            ->where('credit_card_purchase_id', $event->credit_card_purchase_id)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get();

        foreach ($installments as $installment) {
            if ($remaining === 0) {
                break;
            }

            $statement = CreditCardStatement::query()->lockForUpdate()->find($installment->credit_card_statement_id);
            if (! $statement instanceof CreditCardStatement) {
                continue;
            }

            $statementOutstanding = $statement->outstandingCentavos();
            if ($statementOutstanding === 0) {
                continue;
            }

            $applied = min($installment->netAmountCentavos(), $statementOutstanding, $remaining);
            if ($applied === 0) {
                continue;
            }

            $installment->credit_adjustment_centavos += $applied;
            $installment->save();

            CreditCardCreditApplication::query()->create([
                'user_id' => $event->user_id,
                'credit_card_credit_event_id' => $event->id,
                'credit_card_id' => $card->id,
                'credit_card_installment_id' => $installment->id,
                'credit_card_statement_id' => $statement->id,
                'kind' => CreditCardCreditApplicationKind::Installment,
                'amount_centavos' => $applied,
                'applied_at' => $eventDate,
            ]);

            $remaining -= $applied;
            $affected[$statement->id] = ($affected[$statement->id] ?? 0) + $applied;
            $this->reconciler->syncStatement($statement, $this->businessDate());
        }

        if ($remaining > 0) {
            $targets = CreditCardStatement::query()
                ->where('credit_card_id', $card->id)
                ->whereRaw('(original_amount_centavos - credit_adjustment_centavos - paid_centavos - card_credit_applied_centavos) > 0')
                ->orderBy('due_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($targets as $statement) {
                if ($remaining === 0) {
                    break;
                }

                $applied = min($statement->outstandingCentavos(), $remaining);
                if ($applied === 0) {
                    continue;
                }

                CreditCardCreditApplication::query()->create([
                    'user_id' => $event->user_id,
                    'credit_card_credit_event_id' => $event->id,
                    'credit_card_id' => $card->id,
                    'credit_card_installment_id' => null,
                    'credit_card_statement_id' => $statement->id,
                    'kind' => CreditCardCreditApplicationKind::Statement,
                    'amount_centavos' => $applied,
                    'applied_at' => $eventDate,
                ]);

                $remaining -= $applied;
                $affected[$statement->id] = ($affected[$statement->id] ?? 0) + $applied;
                $this->reconciler->syncStatement($statement, $this->businessDate());
            }
        }

        return $affected;
    }

    private function businessDate(): CarbonImmutable
    {
        return $this->cycles->businessToday();
    }
}
