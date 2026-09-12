<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\Exceptions\Transactions\TransactionStateException;
use Illuminate\Support\Facades\DB;

final class TransactionIdempotencyService
{
    /**
     * @param  callable(): array{transaction_id: int, status: int, meta?: array<string, mixed>}  $operation
     * @return array{transaction_id: int, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool}
     */
    public function execute(int $userId, string $key, string $fingerprint, callable $operation): array
    {
        $existing = DB::table('transaction_mutation_requests')
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                throw TransactionStateException::idempotencyKeyReused();
            }

            $payload = json_decode((string) $existing->response_body, true, flags: JSON_THROW_ON_ERROR);

            return [
                'transaction_id' => (int) $existing->transaction_id,
                'status' => (int) $existing->response_status,
                'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
                'response' => $payload,
                'replayed' => true,
            ];
        }

        $result = $operation();
        $meta = $result['meta'] ?? [];

        DB::table('transaction_mutation_requests')->insert([
            'user_id' => $userId,
            'idempotency_key' => $key,
            'request_fingerprint' => $fingerprint,
            'transaction_id' => $result['transaction_id'],
            'response_status' => $result['status'],
            'response_body' => json_encode(['meta' => $meta], JSON_THROW_ON_ERROR),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'transaction_id' => $result['transaction_id'],
            'status' => $result['status'],
            'meta' => $meta,
            'response' => ['meta' => $meta],
            'replayed' => false,
        ];
    }

    /** @param array<string, mixed> $response */
    public function storeResponse(int $userId, string $key, array $response): void
    {
        DB::table('transaction_mutation_requests')
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->update([
                'response_body' => json_encode($response, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
}
