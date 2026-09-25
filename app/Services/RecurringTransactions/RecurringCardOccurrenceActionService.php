<?php

declare(strict_types=1);

namespace App\Services\RecurringTransactions;

use App\Data\RecurringTransactions\ConfirmCardOccurrenceData;
use App\Enums\Categories\CategoryClassification;
use App\Enums\Categories\CategoryStatus;
use App\Enums\CreditCards\CreditCardStatus;
use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Exceptions\CreditCards\CreditCardStateException;
use App\Exceptions\RecurringTransactions\RecurrenceStateException;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\RecurringCardOccurrence;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Services\CreditCards\CreditCardPurchaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class RecurringCardOccurrenceActionService
{
    private const int CLAIM_STALE_MINUTES = 5;

    public function __construct(private readonly CreditCardPurchaseService $purchases) {}

    /** @return array{occurrence: RecurringCardOccurrence, status: int} */
    public function confirm(User $user, RecurringTransaction $rule, RecurringCardOccurrence $occurrence, ConfirmCardOccurrenceData $data): array
    {
        $locked = $this->lockOccurrence($user, $occurrence->id);

        if ($locked->state === CardOccurrenceState::Recorded) {
            return $this->result($locked);
        }
        if ($locked->state === CardOccurrenceState::Dismissed) {
            throw RecurrenceStateException::occurrenceDismissed();
        }

        if ($locked->generation_mode_snapshot === CardGenerationMode::Automatic) {
            return $this->approveOverLimit($user, $locked, $data);
        }

        return $this->confirmExpected($user, $locked, $data);
    }

    /** @return array{occurrence: RecurringCardOccurrence, status: int} */
    public function dismiss(User $user, RecurringTransaction $rule, RecurringCardOccurrence $occurrence): array
    {
        return DB::transaction(function () use ($user, $occurrence): array {
            $locked = $this->lockOccurrence($user, $occurrence->id);

            if ($locked->state === CardOccurrenceState::Dismissed) {
                return $this->result($locked);
            }
            if ($locked->state === CardOccurrenceState::Recorded) {
                throw RecurrenceStateException::occurrenceAlreadyRecorded();
            }
            if ($this->claimIsActive($locked)) {
                throw RecurrenceStateException::occurrenceActionInProgress();
            }

            $locked->state = CardOccurrenceState::Dismissed;
            $locked->dismissed_at = now();
            $locked->action_claim_key = null;
            $locked->action_claimed_at = null;
            $locked->save();

            return $this->result($locked);
        });
    }

    /** @return array{occurrence: RecurringCardOccurrence, status: int} */
    public function retry(User $user, RecurringTransaction $rule, RecurringCardOccurrence $occurrence): array
    {
        $locked = $this->lockOccurrence($user, $occurrence->id);

        if ($locked->state === CardOccurrenceState::Recorded) {
            return $this->result($locked);
        }
        if ($locked->state === CardOccurrenceState::Dismissed) {
            throw RecurrenceStateException::occurrenceDismissed();
        }
        if ($locked->generation_mode_snapshot === CardGenerationMode::Confirmation) {
            throw RecurrenceStateException::occurrenceNotActionable();
        }

        try {
            $this->purchases->recordOccurrencePurchase(
                $user,
                $locked,
                (int) $locked->credit_card_id_original,
                (int) $locked->category_id_original,
                (int) $locked->scheduled_amount_centavos,
                $locked->scheduled_date->toDateString(),
                (string) $locked->description_snapshot,
                $locked->notes_snapshot,
                false,
                null,
            );
        } catch (CreditCardStateException $exception) {
            if ($exception->isOverLimitConfirmationRequired()) {
                $this->markState($locked, CardOccurrenceState::AwaitingOverLimit, null);

                return $this->result($locked->refresh());
            }
            $this->markState($locked, CardOccurrenceState::Failed, 'card_unavailable');

            return $this->result($locked->refresh());
        } catch (RecurrenceStateException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->markState($locked, CardOccurrenceState::Failed, 'purchase_recording_failed');

            return $this->result($locked->refresh());
        }

        return $this->result($locked->refresh());
    }

    /** @return array{occurrence: RecurringCardOccurrence, status: int} */
    private function approveOverLimit(User $user, RecurringCardOccurrence $occurrence, ConfirmCardOccurrenceData $data): array
    {
        try {
            $this->purchases->recordOccurrencePurchase(
                $user,
                $occurrence,
                (int) $occurrence->credit_card_id_original,
                (int) $occurrence->category_id_original,
                (int) $occurrence->scheduled_amount_centavos,
                $occurrence->scheduled_date->toDateString(),
                (string) $occurrence->description_snapshot,
                $occurrence->notes_snapshot,
                $data->confirmOverLimit,
                $data->expectedAvailableCreditCentavos,
            );
        } catch (CreditCardStateException $exception) {
            if ($exception->isOverLimitConfirmationRequired()) {
                throw $exception;
            }
            throw $exception;
        }

        return $this->result($occurrence->refresh());
    }

    /** @return array{occurrence: RecurringCardOccurrence, status: int} */
    private function confirmExpected(User $user, RecurringCardOccurrence $occurrence, ConfirmCardOccurrenceData $data): array
    {
        // Persist the claim and validated choices before the purchase attempt so a
        // competing action cannot overwrite them and a failed attempt stays retryable.
        $plan = DB::transaction(function () use ($user, $occurrence, $data): array {
            $locked = $this->lockOccurrence($user, $occurrence->id);
            // Another request may have recorded the purchase after confirm()
            // read the occurrence but before this transaction acquired its lock.
            if ($locked->state === CardOccurrenceState::Recorded) {
                return ['already_recorded' => true, 'occurrence' => $locked];
            }
            if ($locked->state === CardOccurrenceState::Dismissed) {
                throw RecurrenceStateException::occurrenceDismissed();
            }
            if ($this->claimIsActive($locked)) {
                throw RecurrenceStateException::occurrenceActionInProgress();
            }

            $amount = $data->actualAmountCentavos ?? $locked->actual_amount_centavos ?? $locked->scheduled_amount_centavos;
            $date = $data->actualPurchaseDate ?? $locked->actual_purchase_date?->toDateString() ?? $locked->scheduled_date->toDateString();
            $cardId = $data->creditCardId ?? $locked->credit_card_id_override ?? (int) $locked->credit_card_id_original;
            $categoryId = $data->categoryId ?? $locked->category_id_override ?? (int) $locked->category_id_original;

            $this->ownedActiveCard($user, $cardId);
            $this->availableExpenseCategory($user, $categoryId);
            if ($date > RecurringDateRange::businessDate()) {
                throw ValidationException::withMessages(['actual_purchase_date' => ['The actual purchase date cannot be in the future.']]);
            }

            $locked->actual_amount_centavos = $amount;
            $locked->actual_purchase_date = $date;
            $locked->credit_card_id_override = $data->creditCardId !== null ? $data->creditCardId : $locked->credit_card_id_override;
            $locked->category_id_override = $data->categoryId !== null ? $data->categoryId : $locked->category_id_override;
            $locked->action_claim_key = Str::random(32);
            $locked->action_claimed_at = now();
            $locked->action_choice_version++;
            $locked->failure_code = null;
            $locked->save();

            return [
                'amount' => $amount,
                'date' => $date,
                'card_id' => $cardId,
                'category_id' => $categoryId,
                'occurrence' => $locked,
            ];
        });

        if ($plan['already_recorded'] ?? false) {
            return $this->result($plan['occurrence']);
        }

        try {
            $this->purchases->recordOccurrencePurchase(
                $user,
                $plan['occurrence'],
                $plan['card_id'],
                $plan['category_id'],
                $plan['amount'],
                $plan['date'],
                (string) $plan['occurrence']->description_snapshot,
                $plan['occurrence']->notes_snapshot,
                $data->confirmOverLimit,
                $data->expectedAvailableCreditCentavos,
                $plan['occurrence']->action_claim_key,
                $plan['occurrence']->action_choice_version,
            );
        } catch (CreditCardStateException $exception) {
            $this->releaseClaim($plan['occurrence']);
            throw $exception;
        } catch (RecurrenceStateException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->markFailed($plan['occurrence'], 'purchase_recording_failed');

            return $this->result($this->findOccurrence($user, (int) $plan['occurrence']->id));
        }

        return $this->result($this->findOccurrence($user, (int) $plan['occurrence']->id));
    }

    private function lockOccurrence(User $user, int $occurrenceId): RecurringCardOccurrence
    {
        $occurrence = RecurringCardOccurrence::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->find($occurrenceId);

        if (! $occurrence instanceof RecurringCardOccurrence) {
            throw new NotFoundHttpException('Occurrence not found or not accessible to the signed-in user.');
        }

        return $occurrence;
    }

    private function findOccurrence(User $user, int $occurrenceId): RecurringCardOccurrence
    {
        $occurrence = RecurringCardOccurrence::query()->where('user_id', $user->id)->find($occurrenceId);
        if (! $occurrence instanceof RecurringCardOccurrence) {
            throw new NotFoundHttpException('Occurrence not found or not accessible to the signed-in user.');
        }

        return $occurrence;
    }

    private function claimIsActive(RecurringCardOccurrence $occurrence): bool
    {
        if ($occurrence->action_claim_key === null || $occurrence->action_claimed_at === null) {
            return false;
        }

        if ($occurrence->action_claimed_at->addMinutes(self::CLAIM_STALE_MINUTES)->isPast()) {
            // Stale claim: safe to recover only when no purchase was committed.
            if ($occurrence->purchase()->exists()) {
                throw RecurrenceStateException::occurrenceAlreadyRecorded();
            }

            return false;
        }

        return true;
    }

    private function releaseClaim(RecurringCardOccurrence $claim): void
    {
        RecurringCardOccurrence::query()
            ->whereKey($claim->id)
            ->where('action_claim_key', $claim->action_claim_key)
            ->where('action_choice_version', $claim->action_choice_version)
            ->update([
                'action_claim_key' => null,
                'action_claimed_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function markState(RecurringCardOccurrence $occurrence, CardOccurrenceState $state, ?string $failureCode): void
    {
        RecurringCardOccurrence::query()
            ->whereKey($occurrence->id)
            ->whereIn('state', [
                CardOccurrenceState::Expected->value,
                CardOccurrenceState::AwaitingOverLimit->value,
                CardOccurrenceState::Failed->value,
            ])
            ->whereNull('action_claim_key')
            ->update([
                'state' => $state->value,
                'failure_code' => $failureCode,
                'last_attempt_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function markFailed(RecurringCardOccurrence $claim, string $failureCode): void
    {
        RecurringCardOccurrence::query()
            ->whereKey($claim->id)
            ->where('action_claim_key', $claim->action_claim_key)
            ->where('action_choice_version', $claim->action_choice_version)
            ->update([
                'state' => CardOccurrenceState::Failed->value,
                'failure_code' => $failureCode,
                'last_attempt_at' => now(),
                'action_claim_key' => null,
                'action_claimed_at' => null,
                'updated_at' => now(),
            ]);
    }

    /** @return array{occurrence: RecurringCardOccurrence, status: int} */
    private function result(RecurringCardOccurrence $occurrence): array
    {
        return ['occurrence' => $occurrence->refresh(), 'status' => 200];
    }

    private function ownedActiveCard(User $user, int $cardId): CreditCard
    {
        $card = CreditCard::query()->where('user_id', $user->id)->find($cardId);
        if (! $card instanceof CreditCard) {
            throw new NotFoundHttpException('Credit card not found or not accessible to the signed-in user.');
        }
        if ($card->status !== CreditCardStatus::Active) {
            throw ValidationException::withMessages(['credit_card_id' => ['Archived credit cards cannot be selected.']]);
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
}
