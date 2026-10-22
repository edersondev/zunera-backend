<?php

declare(strict_types=1);

namespace App\Services\RecurringTransactions;

use App\Exceptions\CreditCards\CreditCardStateException;
use App\Exceptions\RecurringTransactions\RecurrenceStateException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class RecurringIdempotencyService
{
    /**
     * @param  callable(): array{recurring_transaction_id: int, status: int, meta?: array<string, mixed>}  $callback
     * @return array{recurring_transaction_id: int, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool}
     */
    public function execute(int $userId, string $key, string $operation, string $fingerprint, callable $callback): array
    {
        $now = now();
        $claimed = DB::table('recurring_mutation_requests')->insertOrIgnore([
            'user_id' => $userId,
            'idempotency_key' => $key,
            'operation' => $operation,
            'request_fingerprint' => $fingerprint,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($claimed === 0) {
            $existing = DB::table('recurring_mutation_requests')
                ->where('user_id', $userId)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                throw new LogicException('Recurrence mutation key claim could not be read.');
            }
            if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                throw RecurrenceStateException::idempotencyKeyReused();
            }

            $payload = json_decode((string) $existing->response_body, true, flags: JSON_THROW_ON_ERROR);
            if (($payload['code'] ?? null) === 'PURCHASE_DELETED') {
                throw CreditCardStateException::purchaseDeleted();
            }

            return [
                'recurring_transaction_id' => (int) $existing->recurring_transaction_id,
                'status' => (int) $existing->response_status,
                'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
                'response' => is_array($payload) ? $payload : [],
                'replayed' => true,
            ];
        }

        $result = $callback();
        $meta = $result['meta'] ?? [];

        DB::table('recurring_mutation_requests')
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->update([
                'recurring_transaction_id' => $result['recurring_transaction_id'],
                'response_status' => $result['status'],
                'response_body' => json_encode(['meta' => $meta], JSON_THROW_ON_ERROR),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'recurring_transaction_id' => $result['recurring_transaction_id'],
            'status' => $result['status'],
            'meta' => $meta,
            'response' => ['meta' => $meta],
            'replayed' => false,
        ];
    }

    /** @param array<string, mixed> $response */
    public function storeResponse(int $userId, string $key, array $response): void
    {
        DB::table('recurring_mutation_requests')
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->update([
                'response_body' => json_encode($response, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function scrubPurchaseResponseSnapshots(int $userId, int $purchaseId): void
    {
        $requests = DB::table('recurring_mutation_requests')
            ->where('user_id', $userId)
            ->whereNotNull('response_body')
            ->lockForUpdate()
            ->get(['id', 'response_body']);
        foreach ($requests as $request) {
            $body = json_decode((string) $request->response_body, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($body) || ! $this->referencesPurchase($body, $purchaseId)) {
                continue;
            }
            DB::table('recurring_mutation_requests')->where('id', $request->id)->update([
                'response_status' => 409,
                'response_body' => json_encode(['code' => 'PURCHASE_DELETED'], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    /** @param array<string|int, mixed> $body */
    private function referencesPurchase(array $body, int $purchaseId): bool
    {
        foreach ($body as $key => $value) {
            if ($key === 'purchase_id' && (int) $value === $purchaseId) {
                return true;
            }
            if ($key === 'purchase' && is_array($value) && (int) ($value['id'] ?? 0) === $purchaseId) {
                return true;
            }
            if (is_array($value) && $this->referencesPurchase($value, $purchaseId)) {
                return true;
            }
        }

        return false;
    }
}
