<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Exceptions\Transfers\TransferStateException;
use Illuminate\Support\Facades\DB;

final class TransferIdempotencyService
{
    /**
     * @param  callable(): array{transfer_id: int, status: int, meta?: array<string, mixed>}  $callback
     * @return array{transfer_id: int, status: int, meta: array<string, mixed>, response: array<string, mixed>, replayed: bool}
     */
    public function execute(int $userId, string $key, string $operation, string $fingerprint, callable $callback): array
    {
        $existing = DB::table('transfer_mutation_requests')
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                throw TransferStateException::idempotencyKeyReused();
            }

            $payload = json_decode((string) $existing->response_body, true, flags: JSON_THROW_ON_ERROR);

            return [
                'transfer_id' => (int) $existing->transfer_id,
                'status' => (int) $existing->response_status,
                'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
                'response' => $payload,
                'replayed' => true,
            ];
        }

        $result = $callback();
        $meta = $result['meta'] ?? [];

        DB::table('transfer_mutation_requests')->insert([
            'user_id' => $userId,
            'idempotency_key' => $key,
            'operation' => $operation,
            'request_fingerprint' => $fingerprint,
            'transfer_id' => $result['transfer_id'],
            'response_status' => $result['status'],
            'response_body' => json_encode(['meta' => $meta], JSON_THROW_ON_ERROR),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'transfer_id' => $result['transfer_id'],
            'status' => $result['status'],
            'meta' => $meta,
            'response' => ['meta' => $meta],
            'replayed' => false,
        ];
    }

    /** @param array<string, mixed> $response */
    public function storeResponse(int $userId, string $key, array $response): void
    {
        DB::table('transfer_mutation_requests')
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->update([
                'response_body' => json_encode($response, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
}
