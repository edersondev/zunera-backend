<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Data\CreditCards\CreateStatementPaymentData;
use App\Data\CreditCards\CreditCardResponseData;
use App\Data\CreditCards\PaymentResponseData;
use App\Data\CreditCards\StatementResponseData;
use App\Data\CreditCards\UpdateStatementPaymentData;
use App\Enums\CreditCards\CreditCardPaymentStatus;
use App\Enums\CreditCards\CreditCardStatementStatus;
use App\Enums\FinancialAccounts\AccountStatus;
use App\Exceptions\CreditCards\CreditCardStateException;
use App\Models\CreditCardStatement;
use App\Models\CreditCardStatementPayment;
use App\Models\FinancialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreditCardStatementPaymentService
{
    public function __construct(
        private readonly CreditCardMutationIdempotencyService $idempotency,
        private readonly CreditCardObligationReconciler $reconciler,
        private readonly CreditCardPaymentAccountReconciler $accounts,
        private readonly BillingCycleCalculator $cycles,
    ) {}

    /** @return array<string, mixed> */
    public function create(User $user, CreditCardStatement $statement, CreateStatementPaymentData $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $statement, $data, $idempotencyKey): array {
            $locked = $this->lockStatement($user, $statement->id);
            $this->reconciler->syncStatement($locked, $this->businessDate());

            $fingerprint = $this->idempotency->fingerprint('payment.create:'.$locked->id, [
                'financial_account_id' => $data->financialAccountId,
                'amount_centavos' => $data->amountCentavos,
                'payment_date' => $data->paymentDate,
                'notes' => $data->notes,
                'status' => $data->status?->value,
            ]);

            $result = $this->idempotency->run($user->id, $idempotencyKey, 'payment.create', $fingerprint, function () use ($user, $locked, $data): array {
                $this->assertPayable($locked);
                $account = $this->availableAccount($user, $data->financialAccountId);
                $this->assertWithinOutstanding($locked, $data->amountCentavos, 0);

                $status = $this->resolveStatus($data->paymentDate, $data->status);
                $payment = CreditCardStatementPayment::query()->create([
                    'user_id' => $user->id,
                    'credit_card_statement_id' => $locked->id,
                    'credit_card_id' => $locked->credit_card_id,
                    'financial_account_id' => $account->id,
                    'amount_centavos' => $data->amountCentavos,
                    'currency_code' => 'BRL',
                    'payment_date' => $data->paymentDate,
                    'notes' => $data->notes,
                    'status' => $status,
                    'removed_at' => null,
                ]);

                $this->accounts->reconcile(null, $payment);
                $this->reconciler->syncStatement($locked, $this->businessDate());

                return [
                    'target_type' => 'credit_card_statement_payment',
                    'target_id' => $payment->id,
                    'status' => 201,
                    'response' => ['data' => $this->payload($payment)],
                ];
            });

            /** @var CreditCardStatementPayment $payment */
            $payment = CreditCardStatementPayment::query()->findOrFail($result['target_id']);

            return [...$result, 'payment' => $payment];
        });
    }

    /** @return array<string, mixed> */
    public function update(User $user, CreditCardStatementPayment $payment, UpdateStatementPaymentData $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $payment, $data, $idempotencyKey): array {
            $locked = $this->lockPayment($user, $payment->id);
            $statement = $this->lockStatement($user, $locked->credit_card_statement_id);
            $this->reconciler->syncStatement($statement, $this->businessDate());

            $fingerprint = $this->idempotency->fingerprint('payment.update:'.$locked->id, $this->serializable($data));

            $result = $this->idempotency->run($user->id, $idempotencyKey, 'payment.update', $fingerprint, function () use ($user, $locked, $statement, $data): array {
                $this->assertEditable($locked);

                $before = clone $locked;
                $accountId = $data->has('financial_account_id') ? (int) $data->changes['financial_account_id'] : (int) $locked->financial_account_id;
                $account = $data->has('financial_account_id') ? $this->availableAccount($user, $accountId) : null;
                $amount = $data->has('amount_centavos') ? (int) $data->changes['amount_centavos'] : (int) $locked->amount_centavos;
                $paymentDate = $data->has('payment_date') ? (string) $data->changes['payment_date'] : $locked->payment_date->toDateString();

                $this->assertWithinOutstanding($statement, $amount, $locked->countsTowardBalance() ? $locked->amount_centavos : 0);

                $locked->fill(array_filter([
                    'financial_account_id' => $account?->id,
                    'amount_centavos' => $data->has('amount_centavos') ? $amount : null,
                    'payment_date' => $data->has('payment_date') ? $paymentDate : null,
                    'notes' => $data->has('notes') ? $data->changes['notes'] : null,
                ], fn ($value) => $value !== null));
                $locked->status = $this->resolveStatus($paymentDate, $data->has('status') ? CreditCardPaymentStatus::from((string) $data->changes['status']) : null);
                $locked->save();

                $this->accounts->reconcile($before, $locked);
                $this->reconciler->syncStatement($statement, $this->businessDate());

                return [
                    'target_type' => 'credit_card_statement_payment',
                    'target_id' => $locked->id,
                    'status' => 200,
                    'response' => ['data' => $this->payload($locked)],
                ];
            });

            /** @var CreditCardStatementPayment $fresh */
            $fresh = CreditCardStatementPayment::query()->findOrFail($result['target_id']);

            return [...$result, 'payment' => $fresh];
        });
    }

    /** @return array<string, mixed> */
    public function remove(User $user, CreditCardStatementPayment $payment, string $idempotencyKey): array
    {
        return $this->lifecycle($user, $payment, 'payment.remove', $idempotencyKey, function (CreditCardStatementPayment $locked): void {
            if ($locked->removed_at !== null) {
                throw CreditCardStateException::paymentAlreadyRemoved();
            }

            $before = clone $locked;
            $locked->removed_at = now();
            $locked->save();
            $this->accounts->reconcile($before, $locked);
        });
    }

    /** @return array<string, mixed> */
    public function restore(User $user, CreditCardStatementPayment $payment, ?CreditCardPaymentStatus $status, string $idempotencyKey): array
    {
        return $this->lifecycle($user, $payment, 'payment.restore', $idempotencyKey, function (CreditCardStatementPayment $locked) use ($status): void {
            if ($locked->removed_at === null) {
                throw CreditCardStateException::paymentAlreadyActive();
            }

            $before = clone $locked;
            $locked->removed_at = null;
            if ($status !== null) {
                $locked->status = $status;
            }
            $locked->save();
            $this->accounts->reconcile($before, $locked);
        });
    }

    public function findOwned(User $user, int $paymentId): CreditCardStatementPayment
    {
        $payment = CreditCardStatementPayment::query()
            ->with(['financialAccount', 'statement', 'creditCard'])
            ->where('user_id', $user->id)
            ->find($paymentId);

        if (! $payment instanceof CreditCardStatementPayment) {
            throw new NotFoundHttpException('Payment not found or not accessible to the signed-in user.');
        }

        return $payment;
    }

    /** @param callable(CreditCardStatementPayment): void $mutate @return array<string, mixed> */
    private function lifecycle(User $user, CreditCardStatementPayment $payment, string $operation, string $idempotencyKey, callable $mutate): array
    {
        return DB::transaction(function () use ($user, $payment, $operation, $idempotencyKey, $mutate): array {
            $locked = $this->lockPayment($user, $payment->id);
            $statement = $this->lockStatement($user, $locked->credit_card_statement_id);
            $fingerprint = $this->idempotency->fingerprint($operation.':'.$locked->id, ['id' => $locked->id]);

            $result = $this->idempotency->run($user->id, $idempotencyKey, $operation, $fingerprint, function () use ($locked, $statement, $mutate): array {
                $mutate($locked);
                $this->reconciler->syncStatement($statement, $this->businessDate());

                return [
                    'target_type' => 'credit_card_statement_payment',
                    'target_id' => $locked->id,
                    'status' => 200,
                    'response' => ['data' => $this->payload($locked)],
                ];
            });

            /** @var CreditCardStatementPayment $fresh */
            $fresh = CreditCardStatementPayment::query()->findOrFail($result['target_id']);

            return [...$result, 'payment' => $fresh];
        });
    }

    /** @return array<string, mixed> */
    public function payloadFor(CreditCardStatementPayment $payment): array
    {
        return $this->payload($payment);
    }

    /** @return array<string, mixed> */
    private function payload(CreditCardStatementPayment $payment): array
    {
        $statement = $payment->statement()->firstOrFail();
        $card = $payment->creditCard()->firstOrFail();
        $businessDate = $this->businessDate();

        return [
            'payment' => PaymentResponseData::from($payment),
            'statement' => StatementResponseData::summary(
                $statement,
                $card,
                StatementResponseData::isCurrentCycle($card, $statement, $businessDate),
                $businessDate,
            ),
            'card' => CreditCardResponseData::card($card, $this->reconciler, $businessDate),
        ];
    }

    private function assertPayable(CreditCardStatement $statement): void
    {
        if (! $statement->status->acceptsPayment()) {
            throw $statement->status === CreditCardStatementStatus::Paid
                ? CreditCardStateException::statementAlreadySettled()
                : CreditCardStateException::statementNotPayable();
        }
    }

    private function assertWithinOutstanding(CreditCardStatement $statement, int $amountCentavos, int $ignoredOwnContribution): void
    {
        if ($amountCentavos < CreditCardMoney::MIN_AMOUNT_CENTAVOS) {
            throw ValidationException::withMessages(['amount_centavos' => ['Payment amount must be positive.']]);
        }

        $allowed = $statement->outstandingCentavos() + $ignoredOwnContribution;
        if ($allowed === 0) {
            throw CreditCardStateException::statementAlreadySettled();
        }

        if ($amountCentavos > $allowed) {
            throw CreditCardStateException::paymentExceedsOutstanding($allowed);
        }
    }

    private function assertEditable(CreditCardStatementPayment $payment): void
    {
        if ($payment->removed_at !== null) {
            throw CreditCardStateException::paymentEditRequiresRestore();
        }
    }

    private function resolveStatus(string $paymentDate, ?CreditCardPaymentStatus $requested): CreditCardPaymentStatus
    {
        $future = CarbonImmutable::parse($paymentDate, CreditCardMoney::BUSINESS_TIME_ZONE)->toDateString()
            > $this->businessDate()->toDateString();

        $status = $requested ?? ($future ? CreditCardPaymentStatus::Pending : CreditCardPaymentStatus::Effective);

        if ($future && $status === CreditCardPaymentStatus::Effective) {
            throw ValidationException::withMessages(['status' => ['Future-dated payments must start as pending.']]);
        }

        return $status;
    }

    private function availableAccount(User $user, int $accountId): FinancialAccount
    {
        $account = FinancialAccount::query()->where('user_id', $user->id)->find($accountId);

        if (! $account instanceof FinancialAccount) {
            throw ValidationException::withMessages(['financial_account_id' => ['Select a financial account you own.']]);
        }

        if ($account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages(['financial_account_id' => ['Select an active financial account for new payments.']]);
        }

        return $account;
    }

    private function lockStatement(User $user, int $statementId): CreditCardStatement
    {
        $statement = CreditCardStatement::query()->where('user_id', $user->id)->lockForUpdate()->find($statementId);

        if (! $statement instanceof CreditCardStatement) {
            throw new NotFoundHttpException('Statement not found or not accessible to the signed-in user.');
        }

        return $statement;
    }

    private function lockPayment(User $user, int $paymentId): CreditCardStatementPayment
    {
        $payment = CreditCardStatementPayment::query()->where('user_id', $user->id)->lockForUpdate()->find($paymentId);

        if (! $payment instanceof CreditCardStatementPayment) {
            throw new NotFoundHttpException('Payment not found or not accessible to the signed-in user.');
        }

        return $payment;
    }

    /** @return array<string, mixed> */
    private function serializable(UpdateStatementPaymentData $data): array
    {
        return $data->changes;
    }

    private function businessDate(): CarbonImmutable
    {
        return $this->cycles->businessToday();
    }
}
