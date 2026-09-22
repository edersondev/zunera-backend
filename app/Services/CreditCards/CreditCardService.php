<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Data\CreditCards\CreateCreditCardData;
use App\Data\CreditCards\CreditCardResponseData;
use App\Data\CreditCards\UpdateCreditCardData;
use App\Enums\CreditCards\CreditCardStatus;
use App\Exceptions\CreditCards\CreditCardStateException;
use App\Models\CreditCard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreditCardService
{
    public function __construct(
        private readonly CreditCardMutationIdempotencyService $idempotency,
        private readonly CreditCardObligationReconciler $reconciler,
        private readonly BillingCycleCalculator $cycles,
    ) {}

    /** @return array{target_type: string, target_id: int, status: int, response: array<string, mixed>, replayed: bool, card: ?CreditCard} */
    public function create(User $user, CreateCreditCardData $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $data, $idempotencyKey): array {
            $fingerprint = $this->idempotency->fingerprint('card.create', [
                'name' => $data->name,
                'institution_name' => $data->institutionName,
                'last_four' => $data->lastFour,
                'color' => $data->color,
                'icon' => $data->icon,
                'credit_limit_centavos' => $data->creditLimitCentavos,
                'closing_day' => $data->closingDay,
                'due_day' => $data->dueDay,
            ]);

            $result = $this->idempotency->run($user->id, $idempotencyKey, 'card.create', $fingerprint, function () use ($user, $data): array {
                $card = CreditCard::query()->create([
                    'user_id' => $user->id,
                    'name' => $data->name,
                    'institution_name' => $data->institutionName,
                    'last_four' => $data->lastFour,
                    'color' => $data->color,
                    'icon' => $data->icon,
                    'credit_limit_centavos' => $data->creditLimitCentavos,
                    'closing_day' => $data->closingDay,
                    'due_day' => $data->dueDay,
                    'status' => CreditCardStatus::Active,
                    'archived_at' => null,
                ]);

                return [
                    'target_type' => 'credit_card',
                    'target_id' => $card->id,
                    'status' => 201,
                    'response' => ['data' => CreditCardResponseData::card($card, $this->reconciler, $this->businessDate())],
                ];
            });

            $card = $result['replayed']
                ? CreditCard::query()->where('user_id', $user->id)->find($result['target_id'])
                : CreditCard::query()->where('user_id', $user->id)->find($result['target_id']);

            return [...$result, 'card' => $card];
        });
    }

    /** @return Collection<int, CreditCard> */
    public function list(User $user, string $view): Collection
    {
        $cards = CreditCard::query()
            ->where('user_id', $user->id)
            ->when(
                $view === 'archived',
                fn ($query) => $query->where('status', CreditCardStatus::Archived),
                fn ($query) => $query->where('status', CreditCardStatus::Active),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $cards->each(fn (CreditCard $card) => $this->syncCardStatements($card));

        return $cards;
    }

    public function findOwned(User $user, int $cardId): CreditCard
    {
        $card = CreditCard::query()->where('user_id', $user->id)->find($cardId);

        if (! $card instanceof CreditCard) {
            throw new NotFoundHttpException('Credit card not found or not accessible to the signed-in user.');
        }

        $this->syncCardStatements($card);

        return $card;
    }

    /** @return array{target_type: string, target_id: int, status: int, response: array<string, mixed>, replayed: bool, card: CreditCard} */
    public function update(User $user, CreditCard $card, UpdateCreditCardData $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $card, $data, $idempotencyKey): array {
            $locked = $this->lockOwnedCard($user, $card->id);
            $fingerprint = $this->idempotency->fingerprint('card.update:'.$locked->id, $data->serializable());

            $result = $this->idempotency->run($user->id, $idempotencyKey, 'card.update', $fingerprint, function () use ($locked, $data): array {
                $locked->fill($this->mutableAttributes($data));
                $locked->save();

                return [
                    'target_type' => 'credit_card',
                    'target_id' => $locked->id,
                    'status' => 200,
                    'response' => ['data' => CreditCardResponseData::card($locked, $this->reconciler, $this->businessDate())],
                ];
            });

            return [...$result, 'card' => $locked->refresh()];
        });
    }

    /** @return array{target_type: string, target_id: int, status: int, response: array<string, mixed>, replayed: bool, card: CreditCard} */
    public function archive(User $user, CreditCard $card, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $card, $idempotencyKey): array {
            $locked = $this->lockOwnedCard($user, $card->id);
            $fingerprint = $this->idempotency->fingerprint('card.archive:'.$locked->id, ['action' => 'archive']);

            $result = $this->idempotency->run($user->id, $idempotencyKey, 'card.archive', $fingerprint, function () use ($locked): array {
                $this->assertArchivable($locked);

                $locked->status = CreditCardStatus::Archived;
                $locked->archived_at = now();
                $locked->save();

                return [
                    'target_type' => 'credit_card',
                    'target_id' => $locked->id,
                    'status' => 200,
                    'response' => ['data' => CreditCardResponseData::card($locked, $this->reconciler, $this->businessDate())],
                ];
            });

            return [...$result, 'card' => $locked->refresh()];
        });
    }

    /** @return array{target_type: string, target_id: int, status: int, response: array<string, mixed>, replayed: bool, card: CreditCard} */
    public function restore(User $user, CreditCard $card, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $card, $idempotencyKey): array {
            $locked = $this->lockOwnedCard($user, $card->id);
            $fingerprint = $this->idempotency->fingerprint('card.restore:'.$locked->id, ['action' => 'restore']);

            $result = $this->idempotency->run($user->id, $idempotencyKey, 'card.restore', $fingerprint, function () use ($locked): array {
                if ($locked->isActive()) {
                    throw CreditCardStateException::cardAlreadyActive();
                }

                $locked->status = CreditCardStatus::Active;
                $locked->archived_at = null;
                $locked->save();

                return [
                    'target_type' => 'credit_card',
                    'target_id' => $locked->id,
                    'status' => 200,
                    'response' => ['data' => CreditCardResponseData::card($locked, $this->reconciler, $this->businessDate())],
                ];
            });

            return [...$result, 'card' => $locked->refresh()];
        });
    }

    public function assertArchivable(CreditCard $card): void
    {
        $outstanding = $this->reconciler->usedCreditCentavos($card);
        if ($outstanding > 0) {
            throw CreditCardStateException::archiveBlockedByOutstanding($outstanding);
        }

        $credit = $this->reconciler->cardCreditCentavos($card);
        if ($credit > 0) {
            throw CreditCardStateException::archiveBlockedByCredit($credit);
        }
    }

    public function findOwnedPurchaseCard(User $user, int $cardId): CreditCard
    {
        return $this->findOwned($user, $cardId);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function mutableAttributes(UpdateCreditCardData $data): array
    {
        return $data->changes;
    }

    private function lockOwnedCard(User $user, int $cardId): CreditCard
    {
        $card = CreditCard::query()->where('user_id', $user->id)->lockForUpdate()->find($cardId);

        if (! $card instanceof CreditCard) {
            throw new NotFoundHttpException('Credit card not found or not accessible to the signed-in user.');
        }

        return $card;
    }

    private function syncCardStatements(CreditCard $card): void
    {
        DB::transaction(fn () => $this->reconciler->refreshCardStatements($card, $this->businessDate()));
    }

    private function businessDate(): CarbonImmutable
    {
        return $this->cycles->businessToday();
    }
}
