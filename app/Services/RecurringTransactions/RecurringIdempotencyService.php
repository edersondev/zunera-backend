<?php

declare(strict_types=1);

namespace App\Services\RecurringTransactions;

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
}
