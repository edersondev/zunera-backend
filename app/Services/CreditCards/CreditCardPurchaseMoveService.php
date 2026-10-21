<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Data\CreditCards\BillingCycleData;
use App\Data\CreditCards\CreditCardResponseData;
use App\Data\CreditCards\MovePurchaseData;
use App\Data\CreditCards\PurchaseResponseData;
use App\Data\CreditCards\StatementResponseData;
use App\Exceptions\CreditCards\CreditCardStateException;
use App\Http\Resources\CreditCards\CreditCardPurchaseMoveResource;
use App\Models\CreditCard;
use App\Models\CreditCardInstallment;
use App\Models\CreditCardPaymentSurplusApplication;
use App\Models\CreditCardPurchase;
use App\Models\CreditCardPurchaseMove;
use App\Models\CreditCardStatement;
use App\Models\User;
use App\Services\Notifications\BudgetNotificationProjector;
use App\Services\Notifications\StatementNotificationProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreditCardPurchaseMoveService
{
    public function __construct(
        private readonly StatementCycleResolver $resolver,
        private readonly BillingCycleCalculator $cycles,
        private readonly CreditCardMutationIdempotencyService $idempotency,
        private readonly CreditCardObligationReconciler $reconciler,
        private readonly CreditCardCreditEventService $creditEvents,
        private readonly StatementNotificationProjector $notifications,
    ) {}

    /** @return array<string, mixed> */
    public function preview(User $user, int $purchaseId, MovePurchaseData $data): array
    {
        $purchase = $this->findOwned($user, $purchaseId);

        return $this->buildPreview($purchase, $data);
    }

    /** @return array<string, mixed> */
    public function move(User $user, int $purchaseId, MovePurchaseData $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $purchaseId, $data, $idempotencyKey): array {
            $this->idempotency->assertNoDeletedReplay((int) $user->id, $idempotencyKey);
            $candidate = $this->findOwned($user, $purchaseId);
            $card = CreditCard::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($candidate->credit_card_id);
            $purchase = CreditCardPurchase::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($purchaseId);
            $fingerprint = $this->idempotency->fingerprint('purchase.move:'.$purchaseId, $data->choice() + [
                'preview_token' => $data->previewToken,
            ]);

            return $this->idempotency->run($user->id, $idempotencyKey, 'purchase.move', $fingerprint, function () use ($user, $card, $purchase, $data, $idempotencyKey): array {
                $preview = $this->buildPreview($purchase, $data);
                if (! hash_equals((string) $preview['preview_token'], (string) $data->previewToken)) {
                    throw CreditCardStateException::staleMovePreview();
                }

                $installments = $purchase->installments()->with('statement')->orderBy('sequence')->lockForUpdate()->get();
                $purchaseDate = $purchase->purchase_date->toDateString();
                $cycles = $data->mode === 'full_schedule'
                    ? $this->resolver->scheduleFrom($card, $purchaseDate, $data->targetClosingDate, $installments->count())
                    : null;
                $target = $data->mode === 'one_installment'
                    ? $this->resolver->resolveChoice($card, $purchaseDate, $data->targetClosingDate)
                    : null;
                $operationId = hash('sha256', $user->id.'|'.$idempotencyKey);
                $moved = [];
                $affectedIds = [];
                $recognitionDates = [];
                $surplusBefore = CreditCardPaymentSurplusApplication::query()
                    ->where('credit_card_id', $card->id)->active()->pluck('destination_statement_id')->all();

                foreach ($installments as $index => $installment) {
                    $destinationCycle = $data->mode === 'full_schedule'
                        ? $cycles[$index]
                        : ($installment->id === $data->installmentId ? $target : null);
                    if (! $destinationCycle instanceof BillingCycleData) {
                        continue;
                    }
                    $source = $installment->statement;
                    if ($source->closing_date->toDateString() === $destinationCycle->closingDate) {
                        continue;
                    }

                    $destination = $this->materialize($card, $destinationCycle);
                    $affectedIds[$source->id] = true;
                    $affectedIds[$destination->id] = true;
                    $recognitionDates[$source->closing_date->toDateString()] = true;
                    $recognitionDates[$destination->closing_date->toDateString()] = true;
                    $installment->credit_card_statement_id = $destination->id;
                    $installment->recognition_date = $destinationCycle->closingDate;
                    $installment->save();

                    $moved[] = CreditCardPurchaseMove::query()->create([
                        'user_id' => $user->id,
                        'credit_card_id' => $card->id,
                        'credit_card_purchase_id' => $purchase->id,
                        'credit_card_installment_id' => $installment->id,
                        'source_statement_id' => $source->id,
                        'destination_statement_id' => $destination->id,
                        'mode' => $data->mode,
                        'sequence' => $installment->sequence,
                        'source_closing_date' => $source->closing_date,
                        'source_period_from' => $source->period_from,
                        'source_period_to' => $source->period_to,
                        'source_due_date' => $source->due_date,
                        'destination_closing_date' => $destination->closing_date,
                        'destination_period_from' => $destination->period_from,
                        'destination_period_to' => $destination->period_to,
                        'destination_due_date' => $destination->due_date,
                        'operation_id' => $operationId,
                        'moved_at' => now(),
                    ]);
                }

                foreach ($this->creditEvents->reallocateForCard($card) as $statementId) {
                    $affectedIds[$statementId] = true;
                }
                $this->reconciler->refreshCardStatements($card, $this->cycles->businessToday());
                foreach (array_merge($surplusBefore, CreditCardPaymentSurplusApplication::query()
                    ->where('credit_card_id', $card->id)->active()->pluck('destination_statement_id')->all()) as $statementId) {
                    $affectedIds[$statementId] = true;
                }
                foreach (array_keys($affectedIds) as $statementId) {
                    $this->notifications->capture(CreditCardStatement::query()->findOrFail($statementId));
                }
                foreach (array_keys($recognitionDates) as $date) {
                    app(BudgetNotificationProjector::class)->captureDate((int) $user->id, $date);
                }

                $fresh = $purchase->fresh();
                $businessDate = $this->cycles->businessToday();
                $payload = [
                    'purchase' => PurchaseResponseData::from($fresh, PurchaseResponseData::isDirectlyEditable($fresh)),
                    'moves' => array_map(fn (CreditCardPurchaseMove $move): array => (new CreditCardPurchaseMoveResource($move))->resolve(), $moved),
                    'affected_statements' => array_map(
                        fn (int $id): array => StatementResponseData::summary(
                            CreditCardStatement::query()->findOrFail($id), $card, false, $businessDate,
                        ),
                        array_map('intval', array_keys($affectedIds)),
                    ),
                    'card' => CreditCardResponseData::card($card, $this->reconciler, $businessDate),
                ];

                return [
                    'target_type' => 'credit_card_purchase',
                    'target_id' => $purchase->id,
                    'status' => 200,
                    'response' => ['data' => $payload],
                ];
            });
        });
    }

    /** @return list<CreditCardPurchaseMove> */
    public function history(User $user, int $purchaseId): array
    {
        $this->findOwned($user, $purchaseId);

        return CreditCardPurchaseMove::query()
            ->where('user_id', $user->id)
            ->where('credit_card_purchase_id', $purchaseId)
            ->orderBy('moved_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function findOwned(User $user, int $purchaseId): CreditCardPurchase
    {
        $purchase = CreditCardPurchase::query()->where('user_id', $user->id)->find($purchaseId);
        if (! $purchase instanceof CreditCardPurchase) {
            throw new NotFoundHttpException('Purchase not found or not accessible to the signed-in user.');
        }
        $card = CreditCard::query()->where('user_id', $user->id)->find($purchase->credit_card_id);
        if (! $card instanceof CreditCard) {
            throw new NotFoundHttpException('Purchase not found or not accessible to the signed-in user.');
        }
        if (! $card->isActive()) {
            throw CreditCardStateException::cardArchived();
        }

        return $purchase;
    }

    /** @return array<string, mixed> */
    private function buildPreview(CreditCardPurchase $purchase, MovePurchaseData $data): array
    {
        $card = $purchase->creditCard;
        $installments = $purchase->installments()->with('statement')->orderBy('sequence')->get();
        $purchaseDate = $purchase->purchase_date->toDateString();
        $fullCycles = $data->mode === 'full_schedule'
            ? $this->resolver->scheduleFrom($card, $purchaseDate, $data->targetClosingDate, $installments->count())
            : null;
        $oneTarget = $data->mode === 'one_installment'
            ? $this->resolver->resolveChoice($card, $purchaseDate, $data->targetClosingDate)
            : null;

        $selected = null;
        $proposed = [];
        $affected = [];
        $impact = [];
        $placements = [];
        $destinationCycles = [];
        foreach ($installments as $index => $installment) {
            $source = $installment->statement;
            $destination = $data->mode === 'full_schedule'
                ? $fullCycles[$index]
                : ($installment->id === $data->installmentId ? $oneTarget : $this->cycleFromStatement($source));
            if ($installment->id === $data->installmentId) {
                $selected = $installment;
            }
            $proposed[] = $destination->closingDate;
            $placements[$installment->id] = $destination->closingDate;
            $destinationCycles[$destination->closingDate] = $destination;
            if ($source->closing_date->toDateString() === $destination->closingDate) {
                continue;
            }

            $sourceOption = $this->optionFor($card, $this->cycleFromStatement($source), $source->id);
            $destinationStatement = CreditCardStatement::query()->where('credit_card_id', $card->id)
                ->whereDate('closing_date', $destination->closingDate)->first();
            $destinationOption = $this->optionFor($card, $destination, $destinationStatement?->id);
            $affected[] = [
                'installment_id' => $installment->id,
                'sequence' => $installment->sequence,
                'amount_centavos' => $installment->amount_centavos,
                'source' => $sourceOption,
                'destination' => $destinationOption,
            ];
            $sourceClosing = $source->closing_date->toDateString();
            $impact[$sourceClosing] = ($impact[$sourceClosing] ?? 0) - $installment->amount_centavos;
            $impact[$destination->closingDate] = ($impact[$destination->closingDate] ?? 0) + $installment->amount_centavos;
        }

        if ($data->mode === 'one_installment' && ! $selected instanceof CreditCardInstallment) {
            throw ValidationException::withMessages(['installment_id' => ['Select an installment from this purchase.']]);
        }
        if ($proposed !== collect($proposed)->sort()->values()->all()) {
            throw ValidationException::withMessages(['target_closing_date' => ['Installment statement dates must remain in installment order.']]);
        }
        if ($affected === []) {
            throw ValidationException::withMessages(['target_closing_date' => ['Choose a statement that changes at least one installment.']]);
        }

        $impacts = $this->simulateImpacts($card, $placements, $destinationCycles, $impact);

        $state = [
            'purchase' => $purchase->only(['id', 'credit_card_id', 'category_id', 'description', 'purchase_date', 'total_amount_centavos', 'installment_count', 'updated_at']),
            'card' => $card->only(['id', 'closing_day', 'due_day', 'status', 'updated_at']),
            'installments' => $installments->map(fn (CreditCardInstallment $installment): array => $installment->only([
                'id', 'credit_card_statement_id', 'sequence', 'amount_centavos', 'credit_adjustment_centavos', 'recognition_date', 'updated_at',
            ]))->all(),
            'statements' => CreditCardStatement::query()->where('credit_card_id', $card->id)->orderBy('id')->get()->map(fn (CreditCardStatement $statement): array => $statement->only([
                'id', 'period_from', 'period_to', 'closing_date', 'due_date', 'original_amount_centavos', 'credit_adjustment_centavos', 'paid_centavos', 'card_credit_applied_centavos', 'updated_at',
            ]))->all(),
            'credit_events' => DB::table('credit_card_credit_events')->where('credit_card_id', $card->id)->orderBy('id')->get(['id', 'amount_centavos', 'updated_at'])->all(),
            'payments' => DB::table('credit_card_statement_payments')->where('credit_card_id', $card->id)->orderBy('id')
                ->get(['id', 'credit_card_statement_id', 'amount_centavos', 'status', 'removed_at', 'updated_at'])->all(),
            'credit_applications' => DB::table('credit_card_credit_applications')->where('credit_card_id', $card->id)->whereNull('superseded_at')->orderBy('id')
                ->get(['id', 'credit_card_credit_event_id', 'credit_card_installment_id', 'credit_card_statement_id', 'kind', 'amount_centavos'])->all(),
            'surplus_applications' => DB::table('credit_card_payment_surplus_applications')->where('credit_card_id', $card->id)->whereNull('superseded_at')->orderBy('id')
                ->get(['id', 'source_statement_id', 'destination_statement_id', 'amount_centavos'])->all(),
        ];
        $token = hash('sha256', json_encode([$data->choice(), $state], JSON_THROW_ON_ERROR));

        return [
            'preview_token' => $token,
            'affected_installments' => $affected,
            'affected_statements' => $impacts,
        ];
    }

    /**
     * Simulate the same credit-event and payment-surplus priority used at save.
     * No statement or application rows are created by preview.
     *
     * @param  array<int, string>  $placements
     * @param  array<string, BillingCycleData>  $destinationCycles
     * @param  array<string, int>  $impact
     * @return list<array<string, int|string>>
     */
    private function simulateImpacts(CreditCard $card, array $placements, array $destinationCycles, array $impact): array
    {
        $statements = CreditCardStatement::query()->where('credit_card_id', $card->id)->get();
        $events = DB::table('credit_card_credit_events')
            ->where('credit_card_id', $card->id)
            ->orderBy('id')
            ->get(['id', 'credit_card_purchase_id', 'amount_centavos']);
        $installmentsByPurchase = CreditCardInstallment::query()
            ->where('credit_card_id', $card->id)
            ->with('statement')
            ->orderBy('credit_card_purchase_id')
            ->orderBy('sequence')
            ->get()
            ->groupBy('credit_card_purchase_id');

        $rows = [];
        foreach ($statements as $statement) {
            $closing = $statement->closing_date->toDateString();
            $rows[$closing] = [
                'id' => (int) $statement->id,
                'due' => $statement->due_date->toDateString(),
                'before' => (int) $statement->original_amount_centavos,
                'before_outstanding' => $statement->outstandingCentavos(),
                'before_credit' => (int) $statement->credit_adjustment_centavos,
                'before_statement_credit' => (int) $statement->card_credit_applied_centavos,
                'before_surplus' => max(0, (int) $statement->paid_centavos - $statement->netAmountCentavos()),
                'gross' => (int) $statement->original_amount_centavos,
                'credit' => (int) $statement->credit_adjustment_centavos,
                'paid' => (int) $statement->paid_centavos,
                'event_credit' => 0,
                'surplus_applied' => 0,
            ];
        }
        foreach ($destinationCycles as $closing => $cycle) {
            $rows[$closing] ??= [
                'id' => PHP_INT_MAX,
                'due' => $cycle->dueDate,
                'before' => 0,
                'before_outstanding' => 0,
                'before_credit' => 0,
                'before_statement_credit' => 0,
                'before_surplus' => 0,
                'gross' => 0,
                'credit' => 0,
                'paid' => 0,
                'event_credit' => 0,
                'surplus_applied' => 0,
            ];
        }
        foreach ($impact as $closing => $delta) {
            $rows[$closing]['gross'] += $delta;
        }

        foreach ($installmentsByPurchase as $installments) {
            foreach ($installments as $installment) {
                $old = $installment->statement->closing_date->toDateString();
                $rows[$old]['credit'] -= (int) $installment->credit_adjustment_centavos;
            }
        }

        $outstanding = static fn (array $row): int => max(0, $row['gross'] - $row['credit'] - $row['paid'] - $row['event_credit'] - $row['surplus_applied']);
        $creditedInstallments = [];
        foreach ($events as $event) {
            $remaining = (int) $event->amount_centavos;
            foreach ($installmentsByPurchase->get($event->credit_card_purchase_id, []) as $installment) {
                $closing = $placements[$installment->id] ?? $installment->statement->closing_date->toDateString();
                $available = (int) $installment->amount_centavos - ($creditedInstallments[$installment->id] ?? 0);
                $applied = min($remaining, $available, $outstanding($rows[$closing]));
                $creditedInstallments[$installment->id] = ($creditedInstallments[$installment->id] ?? 0) + $applied;
                $rows[$closing]['credit'] += $applied;
                $remaining -= $applied;
                if ($remaining === 0) {
                    break;
                }
            }
            if ($remaining > 0) {
                $ordered = array_keys($rows);
                usort($ordered, fn (string $left, string $right): int => [$rows[$left]['due'], $rows[$left]['id']] <=> [$rows[$right]['due'], $rows[$right]['id']]);
                foreach ($ordered as $closing) {
                    $applied = min($remaining, $outstanding($rows[$closing]));
                    $rows[$closing]['event_credit'] += $applied;
                    $remaining -= $applied;
                    if ($remaining === 0) {
                        break;
                    }
                }
            }
        }

        $ordered = array_keys($rows);
        usort($ordered, fn (string $left, string $right): int => [$rows[$left]['due'], $rows[$left]['id']] <=> [$rows[$right]['due'], $rows[$right]['id']]);
        $owed = [];
        foreach ($ordered as $closing) {
            $row = $rows[$closing];
            $owed[$closing] = max(0, $row['gross'] - $row['credit'] - $row['paid'] - $row['event_credit']);
        }
        foreach ($ordered as $source) {
            $surplus = max(0, $rows[$source]['paid'] - max(0, $rows[$source]['gross'] - $rows[$source]['credit']));
            foreach ($ordered as $target) {
                if ($source === $target || $surplus === 0 || $owed[$target] === 0) {
                    continue;
                }
                $applied = min($surplus, $owed[$target]);
                $rows[$target]['surplus_applied'] += $applied;
                $owed[$target] -= $applied;
                $surplus -= $applied;
            }
        }

        ksort($rows);
        $result = [];
        foreach ($rows as $closing => $row) {
            $afterOutstanding = $outstanding($row);
            $afterSurplus = max(0, $row['paid'] - max(0, $row['gross'] - $row['credit']));
            if (! array_key_exists($closing, $impact)
                && $row['before_outstanding'] === $afterOutstanding
                && $row['before_credit'] === $row['credit']
                && $row['before_statement_credit'] === $row['event_credit'] + $row['surplus_applied']
                && $row['before_surplus'] === $afterSurplus) {
                continue;
            }
            $result[] = [
                'closing_date' => $closing,
                'charge_before_centavos' => $row['before'],
                'charge_after_centavos' => $row['gross'],
                'outstanding_before_centavos' => $row['before_outstanding'],
                'outstanding_after_centavos' => $afterOutstanding,
                'payment_surplus_after_centavos' => $afterSurplus,
            ];
        }

        return $result;
    }

    private function cycleFromStatement(CreditCardStatement $statement): BillingCycleData
    {
        return new BillingCycleData(
            $statement->period_from->toDateString(), $statement->period_to->toDateString(),
            $statement->closing_date->toDateString(), $statement->due_date->toDateString(),
        );
    }

    /** @return array<string, mixed> */
    private function optionFor(CreditCard $card, BillingCycleData $cycle, ?int $statementId): array
    {
        return [
            ...$cycle->toArray(),
            'statement_id' => $statementId,
            'closed_by_date' => $cycle->closingDate < $this->cycles->businessToday()->toDateString(),
            'is_persisted' => $statementId !== null,
        ];
    }

    private function materialize(CreditCard $card, BillingCycleData $cycle): CreditCardStatement
    {
        $statement = CreditCardStatement::query()->where('credit_card_id', $card->id)
            ->whereDate('closing_date', $cycle->closingDate)->lockForUpdate()->first();

        return $statement instanceof CreditCardStatement ? $statement : CreditCardStatement::query()->create([
            'user_id' => $card->user_id,
            'credit_card_id' => $card->id,
            ...$cycle->toArray(),
            'status' => 'open',
        ]);
    }
}
